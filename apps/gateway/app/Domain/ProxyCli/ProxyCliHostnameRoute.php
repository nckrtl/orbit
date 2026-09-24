<?php

declare(strict_types=1);

namespace App\Domain\ProxyCli;

use App\Domain\Routes\CustomProxyUpstream;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Route;
use App\Models\RouteCustomProxy;
use Throwable;

/**
 * A Route that holds the collector hostname (ADR 0145). Enable takes over only a custom proxy Route that
 * already serves the collector: on the collector Node, to the loopback collector port, with no Process
 * target. It refuses every other Route on that name before it changes anything.
 */
final readonly class ProxyCliHostnameRoute
{
    public function takeoverCandidate(Node $node, int $port = ProxyCliProcess::PORT): ?Route
    {
        $route = Route::query()
            ->with(['customProxy', 'targets'])
            ->where('domain', ProxyCliHostname::Value)
            ->first();

        if (! $route instanceof Route) {
            return null;
        }

        if (! $this->servesCollector($route, $node, $port)) {
            throw new ResourceOperationException(
                errorCode: 'proxycli.hostname_taken',
                message: "Route [{$route->id}] holds ".ProxyCliHostname::Value.' and is not a custom proxy Route to '
                    ."127.0.0.1:{$port} on Node [{$node->name}]. Remove that Route before you enable proxycli.",
                status: 409,
            );
        }

        return $route;
    }

    private function servesCollector(Route $route, Node $node, int $port): bool
    {
        $proxy = $route->customProxy;

        if (
            ! $route->isCustomProxy()
            || ! $proxy instanceof RouteCustomProxy
            || (int) $route->node_id !== $node->id
            || (int) $proxy->node_id !== $node->id
            || $proxy->process_id !== null
            || $route->targets->isNotEmpty()
        ) {
            return false;
        }

        try {
            return CustomProxyUpstream::parse($proxy->upstream)->authority() === "127.0.0.1:{$port}";
        } catch (Throwable) {
            return false;
        }
    }
}
