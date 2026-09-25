<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Actions\Nodes\GrantGatewayRoleAccessAction;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\RoleBaseline;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Gateway\GatewayPrivateDnsResolver;
use App\Infrastructure\Processes\SystemdVpnOrderingDropIn;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;
use App\Models\NodeRole;

final readonly class GatewayRoleBaseline implements RoleBaseline
{
    public function __construct(
        private NodeRoleFirewallManager $firewall,
        private PrivateDnsManager $dns,
        private NodeRolePrerequisiteCommandFactory $commands,
        private AppDevSshExecutor $ssh,
        private ?GrantGatewayRoleAccessAction $access = null,
        private SystemdVpnOrderingDropIn $vpnOrdering = new SystemdVpnOrderingDropIn,
        private ?VpnSettings $vpnSettings = null,
        private GatewayPrivateDnsResolver $resolver = new GatewayPrivateDnsResolver,
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
        $this->convergeResolver($node);
    }

    private function convergeResolver(Node $node): void
    {
        $settings = $this->vpnSettings ?? app(VpnSettings::class);
        $address = $settings->dnsServer() ?? $this->vpnAddress();

        if ($address === null) {
            return;
        }

        $this->run(
            $node,
            $this->resolver->convergeCommand($address, $settings->domain()),
            'gateway-private-dns-resolver',
            'vpn.dns_resolver_failed',
            60.0,
        );
    }

    /** The WireGuard address of the active `vpn` Node, which serves Orbit VPN DNS. */
    private function vpnAddress(): ?string
    {
        $address = Node::query()
            ->where('status', LifecycleStatus::Active)
            ->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Vpn->value))
            ->value('wireguard_ip');

        return is_string($address) && $address !== '' ? $address : null;
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

    private function grants(): GrantGatewayRoleAccessAction
    {
        return $this->access ?? app(GrantGatewayRoleAccessAction::class);
    }

    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void
    {
        $this->run(
            $node,
            $this->resolver->removeCommand(),
            'gateway-private-dns-resolver',
            'vpn.dns_resolver_failed',
            60.0,
        );
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
