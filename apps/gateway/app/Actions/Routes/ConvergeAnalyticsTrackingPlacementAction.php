<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Domain\Analytics\AnalyticsTrackingRouteProjector;
use App\Domain\AppDev\PrivateDnsAnswerExpiry;
use App\Domain\Routes\RoutePlacement;
use App\Domain\Routes\RouteReplacementStep;
use App\Models\Route;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Moves a tracking host with its Instance's own Route when a Cluster membership or state change
 * moves that Route. The host serves both placements from before private DNS moves until cached
 * answers for the old placement can have expired, like a Project Route placement change.
 */
final readonly class ConvergeAnalyticsTrackingPlacementAction
{
    public function __construct(
        private AnalyticsTrackingRouteProjector $projection,
        private PrivateDnsAnswerExpiry $dnsAnswers = new PrivateDnsAnswerExpiry,
    ) {}

    /**
     * Serves the host at `$placement` beside its current placement, makes `$placement` current, and
     * moves private DNS. The old placement keeps serving until `withdraw()`.
     */
    public function cutOver(Route $route, RoutePlacement $placement): Route
    {
        $route = $route->fresh() ?? $route;

        if ($route->hasPlacementTransition() && $route->replacement_step?->hasReached(RouteReplacementStep::DatabaseCutover) === true) {
            if ($route->transition_dns_moved_at === null) {
                $this->publishDns($route);
            }

            return $route;
        }

        if (
            ! $route->hasPlacementTransition()
            && $route->node_id === $placement->nodeId
            && $route->cluster_id === $placement->clusterId
        ) {
            return $route;
        }

        $current = $this->withPlacement($route, $route->node_id, $route->cluster_id);
        $candidate = $this->withPlacement($route, $placement->nodeId, $placement->clusterId);

        try {
            // The certificate exists before any build names it.
            $this->projection->prepareHost($candidate);
            $route->update([
                'transition_node_id' => $placement->nodeId,
                'transition_cluster_id' => $placement->clusterId,
                'replacement_step' => RouteReplacementStep::Reserved,
            ]);
            $this->projection->buildHost($candidate);
        } catch (Throwable $exception) {
            $this->restore($route, $current, $candidate);

            throw $exception;
        }

        $route->update([
            'node_id' => $placement->nodeId,
            'cluster_id' => $placement->clusterId,
            'transition_node_id' => $current->node_id,
            'transition_cluster_id' => $current->cluster_id,
            'replacement_step' => RouteReplacementStep::DatabaseCutover,
            'transition_dns_moved_at' => null,
        ]);
        $this->publishDns($route);

        return $route;
    }

    public function awaitsWithdrawal(Route $route): bool
    {
        return $route->hasPlacementTransition()
            && in_array($route->replacement_step, [RouteReplacementStep::DatabaseCutover, RouteReplacementStep::Cleanup], true);
    }

    /**
     * Stops serving the old placement once private DNS moved and cached answers can have expired.
     * The stored `cleanup` step lets a retry finish a withdrawal without waiting again.
     */
    public function withdraw(Route $route): Route
    {
        $route = $route->fresh() ?? $route;

        if (! $this->awaitsWithdrawal($route)) {
            return $route;
        }

        if ($route->replacement_step === RouteReplacementStep::DatabaseCutover) {
            if ($route->transition_dns_moved_at === null) {
                return $route;
            }

            $this->dnsAnswers->waitAfter($route->transition_dns_moved_at);
        }

        $retired = $this->withPlacement($route, $route->transition_node_id, $route->transition_cluster_id);
        $current = $this->withPlacement($route, $route->node_id, $route->cluster_id);
        $route->update(['replacement_step' => RouteReplacementStep::Cleanup]);
        $this->projection->withdrawHost($retired, $current);
        $route->update([
            'transition_node_id' => null,
            'transition_cluster_id' => null,
            'transition_dns_moved_at' => null,
            'replacement_step' => null,
        ]);

        return $route;
    }

    private function publishDns(Route $route): void
    {
        $this->projection->publishDns();
        $route->update(['transition_dns_moved_at' => Carbon::now()]);
    }

    /** A failure before cutover withdraws the candidate, so only the current placement serves. */
    private function restore(Route $route, Route $current, Route $candidate): void
    {
        try {
            $route->update([
                'transition_node_id' => null,
                'transition_cluster_id' => null,
                'replacement_step' => null,
            ]);
            $this->projection->withdrawHost($candidate, $current);
        } catch (Throwable) {
            // A retry moves the host forward again from its stored placement.
        }
    }

    private function withPlacement(Route $route, ?int $nodeId, ?int $clusterId): Route
    {
        $placed = $route->replicate();
        $placed->id = $route->id;
        $placed->node_id = $nodeId;
        $placed->cluster_id = $clusterId;
        $placed->setRelations([]);

        return $placed;
    }
}
