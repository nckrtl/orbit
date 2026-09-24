<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Models\Route;

/**
 * Publishes a tracking host on the Node that serves it: its cluster's Router, or its own Node when
 * the host is Node-scoped. The certificate, the Caddy site, and the private DNS record.
 */
interface AnalyticsTrackingRouteProjector
{
    public function converge(Route $route): void;

    /** Issues the tracking host's certificate on the Node that serves `$candidate`'s placement. */
    public function prepareHost(Route $candidate): void;

    /** Builds the Caddy sites of the Node that serves `$placement`'s placement from stored state. */
    public function buildHost(Route $placement): void;

    public function publishDns(): void;

    /**
     * Builds the Node that served `$retired`'s placement and then removes the tracking host's
     * certificate there, unless the same Node also serves `$current`.
     */
    public function withdrawHost(Route $retired, Route $current): void;
}
