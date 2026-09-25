<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\AppDevCaddyManager;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
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
    public function converge(Node $node): void
    {
        $this->owner()->run(function () use ($node): void {
            $this->ssh->execute(
                $node,
                new RemoteCommand($this->vpnOrdering->arguments('caddy'), $this->vpnOrdering->script()),
                step: 'caddy-service-ordering',
                errorCode: 'app-dev.caddy_config_failed',
            );
            $this->buildOwned($node);
        });
    }

    /** Builds the Node after a Route change that the caller already committed. */
    public function build(Node $node): void
    {
        $this->owner()->run(fn () => $this->buildOwned($node));
    }

    /** Role removal: the build renders the Route sites that stored state still places on the Node. */
    public function remove(Node $node): void
    {
        $this->build($node);
    }

    private function buildOwned(Node $node): void
    {
        try {
            $this->builds->build($node);
        } catch (NodeCaddyBuildException $exception) {
            throw new RuntimeConvergenceException(
                step: 'caddy-config',
                errorCode: 'app-dev.caddy_config_failed',
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
