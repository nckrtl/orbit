<?php

declare(strict_types=1);

namespace App\Support\Tui;

use App\Support\Tui\Sources\DatabaseUsersSource;
use App\Support\Tui\Sources\DeploymentsSource;
use App\Support\Tui\Sources\FleetNodeMetricsSource;
use App\Support\Tui\Sources\NodeMetricsSource;
use Closure;

/**
 * Keeps a node's metrics, an AppInstance's deployment history, and a Database connection's
 * users current without Screen ever triggering the Gateway request that fills them.
 *
 * `State::nodeMetrics()`/`deploymentsFor()`/`databaseUsersFor()` used to fetch on first read and
 * re-fetch on a 5-second timer, but Screen calls them while drawing every frame (several times a
 * second), so on a real fleet (nine nodes, some unreachable) the render loop blocked on an HTTP
 * request in the middle of drawing: one synchronous metrics request per node every five seconds,
 * key presses lagging by however long that request took, and quitting waiting on whichever
 * request was in flight.
 *
 * The command loop now owns this scheduler and calls `tick()` once per iteration, before
 * drawing, with whatever is currently visible. `tick()` performs at most one pending fetch —
 * node metrics first (the dashboard's many nodes fetched together in one fleet-wide request, a
 * node page's one node fetched on its own), then deployments, then database users, since
 * normally only one of those three is visible at a time — and writes the result into State via
 * its setters. Every other call is a fast no-op. The command loop's input handling runs after
 * `tick()` and before the next one, so a key press is handled within about the loop's own sleep
 * interval plus at most one background request, which `Sources\Concerns\LimitsBackgroundRequestTime`
 * bounds to a few seconds.
 */
final class RefreshScheduler
{
    private const float METRICS_INTERVAL_SECONDS = 5.0;

    /**
     * The dashboard's fleet-wide fetch runs on its own, longer interval: it is one request no
     * matter how many Nodes there are, and the Metrics role's Prometheus only has new samples
     * every 15s (see `PrometheusConfigRenderer`), so refreshing faster would not show anything new.
     */
    private const float FLEET_METRICS_INTERVAL_SECONDS = 10.0;

    private const float DEPLOYMENTS_INTERVAL_SECONDS = 15.0;

    private const float DATABASE_USERS_INTERVAL_SECONDS = 15.0;

    /** How long a failed (or timed-out) key waits before it is retried, regardless of its normal interval. */
    private const float FAILURE_BACKOFF_SECONDS = 60.0;

    /** @var array<string, float> Last attempt time per "family:key", win or fail. */
    private array $lastAttempt = [];

    /** @var array<string, bool> Whether the last attempt for "family:key" failed (returned null). */
    private array $failed = [];

    private readonly Closure $clock;

    /** @param  null|Closure(): float  $clock  Overridable for tests; defaults to microtime(true). */
    public function __construct(
        private readonly NodeMetricsSource $nodeMetrics,
        private readonly FleetNodeMetricsSource $fleetNodeMetrics,
        private readonly DeploymentsSource $deployments,
        private readonly DatabaseUsersSource $databaseUsers,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * @param  list<int>  $visibleNodeIds  Node metrics to keep current: every node on the
     *                                     dashboard, or the one node a node page is open on.
     * @param  bool  $dashboardVisible  True when $visibleNodeIds is "every node on the
     *                                  dashboard" (fetched with one fleet-wide request), false
     *                                  when it is "the one node a node page is open on" (or
     *                                  empty, fetched per node as before).
     * @param  list<int>  $visibleInstanceIds  Normally at most one: the instance a page is open on.
     * @param  list<string>  $visibleDatabaseSlugs  Normally at most one: the connection a page is open on.
     */
    public function tick(State $state, array $visibleNodeIds, bool $dashboardVisible, array $visibleInstanceIds, array $visibleDatabaseSlugs): void
    {
        $fetchedMetrics = $dashboardVisible
            ? $this->fetchFleetMetricsIfDue($state)
            : $this->fetchOneDue(
                'node',
                $visibleNodeIds,
                self::METRICS_INTERVAL_SECONDS,
                fn (int|string $nodeId): ?array => $this->nodeMetrics->forNode((int) $nodeId),
                fn (int|string $nodeId, ?array $value): null => $state->setNodeMetrics((int) $nodeId, $value),
            );

        if ($fetchedMetrics) {
            return;
        }

        $fetchedDeployments = $this->fetchOneDue(
            'deployment',
            $visibleInstanceIds,
            self::DEPLOYMENTS_INTERVAL_SECONDS,
            fn (int|string $instanceId): ?array => $this->deployments->forInstance((int) $instanceId),
            fn (int|string $instanceId, ?array $value): null => $state->setDeployments((int) $instanceId, $value),
        );

        if ($fetchedDeployments) {
            return;
        }

        $this->fetchOneDue(
            'database',
            $visibleDatabaseSlugs,
            self::DATABASE_USERS_INTERVAL_SECONDS,
            fn (int|string $slug): ?array => $this->databaseUsers->forConnection((string) $slug),
            fn (int|string $slug, ?array $value): null => $state->setDatabaseUsers((string) $slug, $value),
        );
    }

    /**
     * Fetches every Node's metrics in one request when the fleet-wide key is due, storing each
     * Node's result. Unlike `fetchOneDue()`, one attempt covers every visible Node at once, so
     * there is only one "key" here (`"node:__fleet__"`) rather than one per Node id. A Node the
     * response leaves out (unavailable, or the request failed entirely) keeps its last cached
     * value in State rather than being cleared to null, since a fleet failure is not evidence
     * that Node's own metrics stopped existing.
     */
    private function fetchFleetMetricsIfDue(State $state): bool
    {
        $id = 'node:__fleet__';
        $lastAttempt = $this->lastAttempt[$id] ?? 0.0;
        $effectiveInterval = ($this->failed[$id] ?? false) ? self::FAILURE_BACKOFF_SECONDS : self::FLEET_METRICS_INTERVAL_SECONDS;
        $now = ($this->clock)();

        if ($now - $lastAttempt < $effectiveInterval) {
            return false;
        }

        $this->lastAttempt[$id] = $now;
        $metrics = $this->fleetNodeMetrics->forFleet();
        $this->failed[$id] = $metrics === [];

        foreach ($metrics as $nodeId => $value) {
            $state->setNodeMetrics($nodeId, $value);
        }

        return true;
    }

    /**
     * Fetches and stores at most one due key from $keys, returning whether it did. A key is due
     * once $interval has passed since its last attempt, or FAILURE_BACKOFF_SECONDS after a
     * failed one; among several due keys, the most overdue (oldest last attempt, never-fetched
     * first) goes first, so a dashboard with many nodes cycles through all of them fairly rather
     * than starving the ones later in the list.
     *
     * @template TValue
     *
     * @param  list<int|string>  $keys
     * @param  Closure(int|string): TValue  $fetch
     * @param  Closure(int|string, TValue): null  $store
     */
    private function fetchOneDue(string $family, array $keys, float $interval, Closure $fetch, Closure $store): bool
    {
        $key = $this->mostOverdue($family, $keys, $interval);

        if ($key === null) {
            return false;
        }

        $id = "{$family}:{$key}";
        $this->lastAttempt[$id] = ($this->clock)();
        $value = $fetch($key);
        $this->failed[$id] = $value === null;
        $store($key, $value);

        return true;
    }

    /** @param  list<int|string>  $keys */
    private function mostOverdue(string $family, array $keys, float $interval): int|string|null
    {
        $now = ($this->clock)();
        $best = null;
        $bestAttempt = INF;

        foreach ($keys as $key) {
            $id = "{$family}:{$key}";
            $lastAttempt = $this->lastAttempt[$id] ?? 0.0;
            $effectiveInterval = ($this->failed[$id] ?? false) ? self::FAILURE_BACKOFF_SECONDS : $interval;

            if ($now - $lastAttempt < $effectiveInterval) {
                continue;
            }

            if ($lastAttempt < $bestAttempt) {
                $bestAttempt = $lastAttempt;
                $best = $key;
            }
        }

        return $best;
    }
}
