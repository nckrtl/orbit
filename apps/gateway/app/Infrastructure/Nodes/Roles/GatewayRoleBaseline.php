<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Actions\Nodes\GrantGatewayRoleAccessAction;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\GatewayPrivateDnsRoute;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\NodeRoleFollowUpReport;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\RoleBaseline;
use App\Domain\Nodes\RoleName;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Gateway\GatewayPrivateDnsResolver;
use App\Infrastructure\Processes\SystemdVpnOrderingDropIn;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Support\Facades\Log;

final readonly class GatewayRoleBaseline implements GatewayPrivateDnsRoute, RoleBaseline
{
    private const string RESOLVER_STEP = 'gateway-private-dns-resolver';

    private const string RESOLVER_ERROR = 'vpn.dns_resolver_failed';

    public function __construct(
        private NodeRoleFirewallManager $firewall,
        private PrivateDnsManager $dns,
        private NodeRolePrerequisiteCommandFactory $commands,
        private AppDevSshExecutor $ssh,
        private ?GrantGatewayRoleAccessAction $access = null,
        private SystemdVpnOrderingDropIn $vpnOrdering = new SystemdVpnOrderingDropIn,
        private ?VpnSettings $vpnSettings = null,
        private GatewayPrivateDnsResolver $resolver = new GatewayPrivateDnsResolver,
        private ?NodeRoleFollowUpReport $followUps = null,
        private ?NodeRoleConvergeLock $nodeLock = null,
    ) {}

    /**
     * Installs Caddy from the pinned source and orders it after `wg-quick@orbit` first, the same
     * step Gateway bootstrap runs, so converging the role repairs what Doctor reports for it.
     * After private DNS publishes, it routes the private domain on the Gateway machine to VPN DNS
     * (ADR 0156), so clients there resolve `reverb.orbit` and the other private names.
     */
    public function converge(Node $node, NodeRole $assignment): void
    {
        $caddySource = $this->commands->caddySource($node, RoleName::Gateway);
        if ($caddySource instanceof RemoteCommand) {
            $this->run($node, $caddySource, 'caddy-package-source', 'gateway.caddy_install_failed');
            $this->run(
                $node,
                new RemoteCommand($this->vpnOrdering->arguments('caddy'), $this->vpnOrdering->script()),
                'caddy-ordering',
                'gateway.caddy_start_failed',
                60.0,
            );
        }
        $this->firewall->converge($node, RoleName::Gateway, $node->user);
        $this->grants()->execute($node);
        $this->dns->converge();
        $this->convergeRoute($node);
    }

    /**
     * Routes the private domain to VPN DNS without failing the role: a `failed` gateway role would
     * drop implicit Gateway authority, which realtime and metrics authorization rely on. The role
     * response reports the failure as `follow_up`, the log keeps its underlying error code, and
     * Doctor reports a missing route as `role.private_dns_route_mismatch`.
     */
    public function convergeRoute(Node $node): void
    {
        $settings = $this->vpnSettings ?? app(VpnSettings::class);
        $address = $this->resolver->vpnDnsAddress($settings);

        if ($address === null) {
            return;
        }

        try {
            // Re-entrant: role convergence already holds it, and relocation takes it here.
            $this->nodeLock()->run($node, fn () => $this->run(
                $node,
                $this->resolver->convergeCommand($address, $settings->domain()),
                self::RESOLVER_STEP,
                self::RESOLVER_ERROR,
                60.0,
            ));
        } catch (NodeRoleOperationException|NodeProvisioningException $exception) {
            $errorCode = $exception instanceof NodeRoleOperationException
                ? $exception->underlyingErrorCode
                : $exception->errorCode;
            Log::warning('The Gateway private DNS route step failed; the gateway role stays converged.', [
                'node' => $node->name,
                'error_code' => $errorCode,
                'exit_code' => $exception->result?->exitCode,
            ]);
            ($this->followUps ?? app(NodeRoleFollowUpReport::class))->record(
                "The Gateway machine does not route the private domain to Orbit VPN DNS ({$errorCode}). "
                ."The gateway role stays active. Fix the cause, then run `orbit node:role:add {$node->name} gateway --converge` again.",
            );
        }
    }

    /**
     * Runs one Gateway role step over SSH and names the Gateway role in its failure, so the API
     * message matches the step instead of the shared executor's wording.
     */
    private function run(
        Node $node,
        RemoteCommand $command,
        string $step,
        string $errorCode,
        ?float $commandTimeout = null,
    ): void {
        try {
            $this->ssh->execute($node, $command, $step, $errorCode, $commandTimeout);
        } catch (RuntimeConvergenceException $exception) {
            throw new NodeRoleOperationException(
                step: $step,
                errorCode: 'node_role.convergence_failed',
                underlyingErrorCode: $exception->errorCode,
                message: "Gateway role step [{$step}] failed on node [{$node->name}].",
                result: $exception->result,
                previous: $exception,
            );
        }
    }

    public function removeRoute(Node $node): void
    {
        $this->nodeLock()->run($node, function () use ($node): void {
            try {
                $this->ssh->execute($node, $this->resolver->removeCommand(), self::RESOLVER_STEP, self::RESOLVER_ERROR, 60.0);
            } catch (RuntimeConvergenceException $exception) {
                throw new NodeRoleOperationException(
                    step: self::RESOLVER_STEP,
                    errorCode: 'node_role.remove_failed',
                    underlyingErrorCode: $exception->errorCode,
                    message: 'Gateway role step ['.self::RESOLVER_STEP."] failed on node [{$node->name}].",
                    result: $exception->result,
                    previous: $exception,
                );
            }
        }, 'node_role.remove_failed', self::RESOLVER_STEP);
    }

    private function nodeLock(): NodeRoleConvergeLock
    {
        return $this->nodeLock ?? app(NodeRoleConvergeLock::class);
    }

    private function grants(): GrantGatewayRoleAccessAction
    {
        return $this->access ?? app(GrantGatewayRoleAccessAction::class);
    }

    /**
     * Removes the private DNS route first. Its failure fails the removal like any other step, so
     * the drop-in never stays behind unreported on a machine that no longer holds `gateway`.
     */
    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void
    {
        $this->removeRoute($node);
        $this->firewall->remove($node, RoleName::Gateway, $node->user);
        $this->dns->converge();
    }

    /**
     * Removes only what lives on the Gateway, for a gateway node Orbit
     * cannot reach: the private DNS record. Caddy, PHP-FPM, the serving
     * checkout, and the HTTPS firewall stay on the box.
     */
    public function removeUnreachable(Node $node, NodeRole $assignment): void
    {
        $this->dns->converge();
    }
}
