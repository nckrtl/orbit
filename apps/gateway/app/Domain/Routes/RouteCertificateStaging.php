<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Models\Route;

/**
 * Chooses from stored Route state whether a Route's sites answer from the staging certificates
 * its change issues. A domain change serves its replacement from the staging scopes until cleanup
 * has issued the live ones: the Instance's live leaf names the current domain, and the
 * replacement's live Router leaf does not exist yet. A placement change serves its candidate
 * Router sites from the staging Router scope for the same reason.
 */
final class RouteCertificateStaging
{
    /** The workload sites answer from `app-instance-<id>-hostname-change`. */
    public static function workload(Route $route): bool
    {
        return self::domainChange($route);
    }

    /** The Router and composed pool sites of the current placement answer from `route-<id>-router-hostname-change`. */
    public static function router(Route $route): bool
    {
        return self::domainChange($route) || self::placementCutOver($route);
    }

    /**
     * A domain change replacement before cutover, or after cutover until its cleanup has issued
     * the live certificates.
     */
    public static function domainChange(Route $route): bool
    {
        return $route->replaces_route_id !== null
            && in_array($route->status, [RouteStatus::Pending, RouteStatus::Activating], true)
            && $route->replacement_step !== RouteReplacementStep::Cleanup;
    }

    /**
     * A placement change after `database-cutover`: the Route holds the candidate placement and the
     * transition columns hold the old one until cleanup issues the live Router leaf.
     */
    public static function placementCutOver(Route $route): bool
    {
        return $route->hasPlacementTransition()
            && $route->replacement_step instanceof RouteReplacementStep
            && $route->replacement_step !== RouteReplacementStep::Cleanup
            && $route->replacement_step->hasReached(RouteReplacementStep::DatabaseCutover);
    }

    /** A placement change before `database-cutover`: the transition columns hold the candidate. */
    public static function placementCandidate(Route $route): bool
    {
        return $route->hasPlacementTransition()
            && ! self::placementCutOver($route)
            && $route->replacement_step !== RouteReplacementStep::Cleanup;
    }

    /** Whether the stored change has completed the step that issues a certificate. */
    public static function reached(Route $route, RouteReplacementStep $step): bool
    {
        return $route->replacement_step?->hasReached($step) === true;
    }
}
