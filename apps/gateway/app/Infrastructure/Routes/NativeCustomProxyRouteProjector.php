<?php

declare(strict_types=1);

namespace App\Infrastructure\Routes;

use App\Domain\Routes\CustomProxyRouteProjector;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\AppDev\RemoteAppDevCertificateManager;
use App\Models\Node;
use App\Models\Route;
use RuntimeException;

final readonly class NativeCustomProxyRouteProjector implements CustomProxyRouteProjector
{
    public function __construct(
        private RemoteAppDevCertificateManager $certificates,
        private RemoteAppDevCaddyManager $caddy,
        private DnsmasqPrivateDnsManager $dns,
    ) {}

    public function converge(Route $route): void
    {
        $route->loadMissing(['node', 'customProxy']);
        $node = $route->node;

        if (! $node instanceof Node) {
            throw new RuntimeException('A custom proxy Route requires a serving Node.');
        }

        // Creation stores the publication record before its first build renders the Route.
        $route->publishSites();
        $this->certificates->convergeCustomProxy($route, $node);
        $this->caddy->converge($node);
        $this->dns->converge();
    }
}
