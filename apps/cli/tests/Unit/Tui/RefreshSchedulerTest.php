<?php

declare(strict_types=1);

use App\Support\Tui\RefreshScheduler;
use App\Support\Tui\Sources\DatabaseUsersSource;
use App\Support\Tui\Sources\DeploymentsSource;
use App\Support\Tui\Sources\NodeMetricsSource;
use App\Support\Tui\State;

/** A NodeMetricsSource that counts calls per node id and returns a fixed result (or null to simulate a failure). */
final class CountingNodeMetricsSource implements NodeMetricsSource
{
    /** @var array<int, int> */
    public array $calls = [];

    /**
     * @param  array<int, array{cores: list<float>, mem: array{float, float}, swap: array{float, float}, uptime: string, disks: list<array{string, float, float}>}|null>  $results  Keyed by node id; missing keys return $default.
     * @param  array{cores: list<float>, mem: array{float, float}, swap: array{float, float}, uptime: string, disks: list<array{string, float, float}>}|null  $default
     */
    public function __construct(private readonly array $results = [], private readonly ?array $default = null) {}

    #[Override]
    public function forNode(int $nodeId): ?array
    {
        $this->calls[$nodeId] = ($this->calls[$nodeId] ?? 0) + 1;

        return array_key_exists($nodeId, $this->results) ? $this->results[$nodeId] : $this->default;
    }

    public function totalCalls(): int
    {
        return array_sum($this->calls);
    }
}

/** A DatabaseUsersSource that never gets called in these tests (the scheduler must not reach it while metrics/deployments are due). */
final class NeverCalledDatabaseUsersSource implements DatabaseUsersSource
{
    public int $calls = 0;

    /** @return list<never> */
    #[Override]
    public function forConnection(string $slug): array
    {
        $this->calls++;

        return [];
    }
}

/**
 * A simple closure-backed source used where the test only needs to count invocations, not the
 * family's real return shape.
 *
 * @param  list<array<string, mixed>>|null  $result
 */
function counting_deployments_source(int &$calls, ?array $result = []): DeploymentsSource
{
    return new class($calls, $result) implements DeploymentsSource
    {
        /** @param  list<array<string, mixed>>|null  $result */
        public function __construct(private int &$calls, private readonly ?array $result) {}

        #[Override]
        public function forInstance(int $instanceId): ?array
        {
            $this->calls++;

            return $this->result;
        }
    };
}

/** @param  list<array<string, mixed>>|null  $result */
function counting_database_users_source(int &$calls, ?array $result = []): DatabaseUsersSource
{
    return new class($calls, $result) implements DatabaseUsersSource
    {
        /** @param  list<array<string, mixed>>|null  $result */
        public function __construct(private int &$calls, private readonly ?array $result) {}

        #[Override]
        public function forConnection(string $slug): ?array
        {
            $this->calls++;

            return $this->result;
        }
    };
}

/** A mutable clock the tests advance explicitly, instead of sleeping for real. */
final class FakeClock
{
    public function __construct(private float $now = 1_000.0) {}

    public function closure(): Closure
    {
        return fn (): float => $this->now;
    }

    public function advance(float $seconds): void
    {
        $this->now += $seconds;
    }
}

describe(RefreshScheduler::class, function (): void {
    it('never fetches when nothing is visible', function (): void {
        $metrics = new CountingNodeMetricsSource;
        $deploymentCalls = 0;
        $databaseCalls = 0;
        $scheduler = new RefreshScheduler($metrics, counting_deployments_source($deploymentCalls), counting_database_users_source($databaseCalls));
        $state = new State;

        $scheduler->tick($state, [], [], []);

        expect($metrics->totalCalls())->toBe(0)
            ->and($deploymentCalls)->toBe(0)
            ->and($databaseCalls)->toBe(0);
    });

    it('fetches a visible node once and stores the result on State, without Screen or State triggering it', function (): void {
        $metrics = new CountingNodeMetricsSource(results: [1 => ['cores' => [0.5], 'mem' => [1.0, 2.0], 'swap' => [0.0, 0.0], 'uptime' => '1m', 'disks' => [['/', 1.0, 2.0]]]]);
        $deploymentCalls = 0;
        $databaseCalls = 0;
        $scheduler = new RefreshScheduler($metrics, counting_deployments_source($deploymentCalls), counting_database_users_source($databaseCalls));
        $state = new State;

        expect($state->nodeMetrics(1))->toBeNull();

        $scheduler->tick($state, [1], [], []);

        expect($metrics->totalCalls())->toBe(1)
            ->and($state->nodeMetrics(1))->not->toBeNull()
            ->and($state->nodeMetrics(1)['uptime'])->toBe('1m');
    });

    it('performs at most one fetch per tick, even with several visible nodes', function (): void {
        $metrics = new CountingNodeMetricsSource;
        $deploymentCalls = 0;
        $databaseCalls = 0;
        $scheduler = new RefreshScheduler($metrics, counting_deployments_source($deploymentCalls), counting_database_users_source($databaseCalls));
        $state = new State;

        $scheduler->tick($state, [1, 2, 3], [], []);

        expect($metrics->totalCalls())->toBe(1);
    });

    it('does not re-fetch a node before its 5-second interval has passed', function (): void {
        $clock = new FakeClock;
        $metrics = new CountingNodeMetricsSource(results: [1 => ['cores' => [0.1], 'mem' => [1.0, 2.0], 'swap' => [0.0, 0.0], 'uptime' => '1m', 'disks' => [['/', 1.0, 2.0]]]]);
        $deploymentCalls = 0;
        $databaseCalls = 0;
        $scheduler = new RefreshScheduler($metrics, counting_deployments_source($deploymentCalls), counting_database_users_source($databaseCalls), $clock->closure());
        $state = new State;

        $scheduler->tick($state, [1], [], []);
        expect($metrics->totalCalls())->toBe(1);

        $clock->advance(4.0);
        $scheduler->tick($state, [1], [], []);
        expect($metrics->totalCalls())->toBe(1);

        $clock->advance(1.5);
        $scheduler->tick($state, [1], [], []);
        expect($metrics->totalCalls())->toBe(2);
    });

    it('cycles through several visible nodes fairly, oldest fetch first, one per tick', function (): void {
        $clock = new FakeClock;
        $metrics = new CountingNodeMetricsSource;
        $deploymentCalls = 0;
        $databaseCalls = 0;
        $scheduler = new RefreshScheduler($metrics, counting_deployments_source($deploymentCalls), counting_database_users_source($databaseCalls), $clock->closure());
        $state = new State;

        $scheduler->tick($state, [1, 2, 3], [], []);
        $scheduler->tick($state, [1, 2, 3], [], []);
        $scheduler->tick($state, [1, 2, 3], [], []);

        expect($metrics->calls)->toBe([1 => 1, 2 => 1, 3 => 1]);
    });

    it('backs off a failed node to 60 seconds instead of its normal 5-second interval', function (): void {
        $clock = new FakeClock;
        $metrics = new CountingNodeMetricsSource(default: null); // Every fetch "fails" (returns null).
        $deploymentCalls = 0;
        $databaseCalls = 0;
        $scheduler = new RefreshScheduler($metrics, counting_deployments_source($deploymentCalls), counting_database_users_source($databaseCalls), $clock->closure());
        $state = new State;

        $scheduler->tick($state, [1], [], []);
        expect($metrics->totalCalls())->toBe(1)
            ->and($state->nodeMetrics(1))->toBeNull();

        // Past the normal 5s interval, but under the 60s failure backoff: still not retried.
        $clock->advance(10.0);
        $scheduler->tick($state, [1], [], []);
        expect($metrics->totalCalls())->toBe(1);

        $clock->advance(50.5); // 60.5s since the failed attempt.
        $scheduler->tick($state, [1], [], []);
        expect($metrics->totalCalls())->toBe(2);
    });

    it('only reaches deployments once no node metrics are due, and only reaches database users once no deployments are due', function (): void {
        $clock = new FakeClock;
        $metrics = new CountingNodeMetricsSource;
        $deploymentCalls = 0;
        $databaseUsers = new NeverCalledDatabaseUsersSource;
        $scheduler = new RefreshScheduler($metrics, counting_deployments_source($deploymentCalls), $databaseUsers, $clock->closure());
        $state = new State;

        // Node metrics are due, so this tick fetches metrics only.
        $scheduler->tick($state, [1], [10], []);
        expect($metrics->totalCalls())->toBe(1)
            ->and($deploymentCalls)->toBe(0)
            ->and($databaseUsers->calls)->toBe(0);

        // Node metrics are no longer due (just fetched); deployments are due next.
        $scheduler->tick($state, [1], [10], []);
        expect($metrics->totalCalls())->toBe(1)
            ->and($deploymentCalls)->toBe(1)
            ->and($databaseUsers->calls)->toBe(0);
    });
});
