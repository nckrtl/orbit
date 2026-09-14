<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\AppDevCaddyManager;
use App\Domain\AppDev\AppDevTldConverger;
use App\Domain\AppDev\AppDevTldRouteManager;
use App\Domain\AppDev\PrivateDnsManager;
use App\Models\Node;

final readonly class NativeAppDevTldConverger implements AppDevTldConverger
{
    public function __construct(
        private AppDevCaddyManager $caddy,
        private PrivateDnsManager $dns,
        private AppDevTldRouteManager $routes,
    ) {}

    public function converge(Node $node): void
    {
        $this->caddy->converge($node);
        $this->dns->converge($node);
        $this->routes->converge($node);
    }
}
