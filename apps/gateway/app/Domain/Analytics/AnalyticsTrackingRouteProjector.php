<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Models\Route;

/** Publishes a tracking host on its cluster's Router: the certificate, the Caddy site, and the private DNS record. */
interface AnalyticsTrackingRouteProjector
{
    public function converge(Route $route): void;
}
