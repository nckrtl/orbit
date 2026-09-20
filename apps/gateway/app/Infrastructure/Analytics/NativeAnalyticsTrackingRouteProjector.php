<?php

declare(strict_types=1);

namespace App\Infrastructure\Analytics;

use App\Domain\Analytics\AnalyticsTrackingRouteProjector;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\AppDev\RemoteAppDevCertificateManager;
use App\Infrastructure\Routes\IngressSiteRepository;
use App\Models\Route;

final readonly class NativeAnalyticsTrackingRouteProjector implements AnalyticsTrackingRouteProjector
{
    public function __construct(
        private IngressSiteRepository $sites,
        private RemoteAppDevCertificateManager $certificates,
        private RemoteAppDevCaddyManager $caddy,
        private DnsmasqPrivateDnsManager $dns,
    ) {}

    public function converge(Route $route): void
    {
        $router = $this->sites->routerNode($route);

        $this->certificates->convergeRouteRouter($route, $router);
        $this->caddy->convergeRoute($router, $route);
        $this->dns->convergeRoute($route);
    }
}
