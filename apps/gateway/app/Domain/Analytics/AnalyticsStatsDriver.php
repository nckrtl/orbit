<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

/**
 * Reads visits for one App instance from the fleet analytics service.
 *
 * The first implementation talks to the fleet Plausible Community Edition Stats API
 * ([ADR 0102](/decisions/0102-read-app-instance-analytics-through-a-fleet-driver)).
 */
interface AnalyticsStatsDriver
{
    /** Stable driver name, such as `plausible_ce`. */
    public function name(): string;

    /**
     * True while a Node has an active analytics role that this driver can address.
     * A missing Stats API key is a failed read, not an unhealthy fleet.
     */
    public function fleetHealthy(): bool;

    /**
     * Stats for one Plausible site, or a failed read with no visitor numbers.
     * `$siteDomain` is the App instance's authoritative public domain.
     */
    public function read(string $siteDomain): AnalyticsStatsRead;
}
