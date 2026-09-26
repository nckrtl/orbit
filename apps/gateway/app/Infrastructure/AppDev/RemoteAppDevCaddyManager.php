<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\AppDevCaddyManager;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Infrastructure\Nodes\Roles\CaddyRoleFailure;
use App\Infrastructure\Processes\SystemdVpnOrderingDropIn;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;

/**
 * Publishes a Node's Route sites by requesting a Node Caddy build (ADR 0141). Every caller commits the
 * Route state it changed first; the build renders every site on the Node from that state.
 */
final readonly class RemoteAppDevCaddyManager implements AppDevCaddyManager
{
    public function __construct(
        private NodeCaddyBuilds $builds,
        private AppDevSshExecutor $ssh,
        private ?DevelopmentProjectionOperationLock $projection = null,
        private SystemdVpnOrderingDropIn $vpnOrdering = new SystemdVpnOrderingDropIn,
    ) {}

    /** Role convergence: orders Caddy after the WireGuard interface, then builds the Node. */
    public function converge(Node $node, RoleName $role = RoleName::AppDev): void
    {
        $this->owner()->run(function () use ($node, $role): void {
            $this->ssh->execute(
                $node,
                new RemoteCommand($this->vpnOrdering->arguments('caddy'), $this->vpnOrdering->script()),
                step: 'caddy-service-ordering',
                errorCode: CaddyRoleFailure::code($role),
                failureLabel: CaddyRoleFailure::sshLabel($role),
            );
            $this->buildOwned($node, $role);
        });
    }

    /**
     * Builds the Node after a Route change that the caller already committed.
     *
     * Route publication, including a public Ingress site, stays on the app-dev code. The ingress
     * role's own build uses converge() and remove().
     */
    public function build(Node $node): void
    {
        $this->owner()->run(fn () => $this->buildOwned($node, RoleName::AppDev));
    }

    /** Role removal: the build renders the Route sites that stored state still places on the Node. */
    public function remove(Node $node, RoleName $role = RoleName::AppDev): void
    {
        $this->owner()->run(fn () => $this->buildOwned($node, $role));
    }

    private function buildOwned(Node $node, RoleName $role): void
    {
        try {
            $this->builds->build($node);
        } catch (NodeCaddyBuildException $exception) {
            throw new RuntimeConvergenceException(
                step: 'caddy-config',
                errorCode: CaddyRoleFailure::code($role),
                message: $exception->getMessage(),
                previous: $exception,
                result: $exception->result(),
            );
        }
    }

    private function owner(): DevelopmentProjectionOperationLock
    {
        return $this->projection ?? app(DevelopmentProjectionOperationLock::class);
    }
}
