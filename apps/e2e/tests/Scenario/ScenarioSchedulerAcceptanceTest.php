<?php

declare(strict_types=1);

use App\E2E\Git\GitRepository;
use App\E2E\IncusHost;
use App\E2E\State\StatePaths;
use Symfony\Component\Process\Process;

/** @param list<string> $scenarios */
function startSchedulerAcceptanceProcess(array $scenarios, int $workers): Process
{
    $repository = dirname(__DIR__, 4);
    $candidate = (new GitRepository($repository))->commit();
    $primary = getenv('ORBIT_SCENARIO_PRIMARY_ROOT');
    if (! is_string($primary) || ! str_starts_with($primary, '/')) {
        throw new RuntimeException('The scenario primary checkout is absent.');
    }

    $process = new Process([
        PHP_BINARY,
        'artisan',
        'scenario:run',
        "--workers={$workers}",
        ...array_map(static fn (string $scenario): string => "--scenario={$scenario}", $scenarios),
    ], dirname(__DIR__, 2), [
        'ORBIT_SCENARIO_CANDIDATE_SHA' => $candidate,
        'ORBIT_SCENARIO_REPOSITORY' => $repository,
        'ORBIT_SCENARIO_PRIMARY_ROOT' => $primary,
    ]);
    $process->setTimeout(null);
    $process->start();

    return $process;
}

/** @return array<string, mixed> */
function readSchedulerAcceptanceJson(string $path): array
{
    $contents = file_get_contents($path);
    if (! is_string($contents)) {
        throw new RuntimeException("Cannot read scheduler acceptance state [{$path}].");
    }
    $value = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($value) || array_is_list($value)) {
        throw new RuntimeException("Scheduler acceptance state [{$path}] is invalid.");
    }

    return $value;
}

/** @return list<string> */
function schedulerAcceptanceRunIds(StatePaths $paths): array
{
    $directories = glob($paths->path('scenarios/runs/*'), GLOB_ONLYDIR);
    if ($directories === false) {
        throw new RuntimeException('Cannot list scenario scheduler runs.');
    }

    return array_values(array_map('basename', $directories));
}

/** @param list<string> $before */
function awaitSchedulerAcceptanceRun(StatePaths $paths, array $before, float $timeout = 30.0): string
{
    $deadline = microtime(true) + $timeout;
    do {
        $created = array_values(array_diff(schedulerAcceptanceRunIds($paths), $before));
        if (count($created) === 1) {
            return $created[0];
        }
        if (count($created) > 1) {
            throw new RuntimeException('More than one scenario scheduler run started.');
        }
        usleep(100_000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException('The scenario scheduler did not create run state.');
}

/** @return list<array<string, mixed>> */
function schedulerAcceptanceAttempts(StatePaths $paths, string $run): array
{
    $attemptFiles = glob($paths->path("scenarios/runs/{$run}/*/*/attempt.json"));
    if ($attemptFiles === false) {
        throw new RuntimeException('Cannot list scenario scheduler attempts.');
    }
    sort($attemptFiles, SORT_STRING);

    return array_map(readSchedulerAcceptanceJson(...), $attemptFiles);
}

/** @return array<string, mixed> */
function awaitSchedulerAcceptanceAggregate(StatePaths $paths, string $run, float $timeout = 60.0): array
{
    $path = $paths->path("scenarios/runs/{$run}/aggregate.json");
    $deadline = microtime(true) + $timeout;
    while (! is_file($path) && microtime(true) < $deadline) {
        usleep(100_000);
    }
    if (! is_file($path)) {
        throw new RuntimeException('The scenario scheduler did not write its aggregate.');
    }

    return readSchedulerAcceptanceJson($path);
}

/** @param Closure(list<array<string, mixed>>): void $observe */
function observeSchedulerAcceptanceProcess(
    Process $process,
    StatePaths $paths,
    string $run,
    Closure $observe,
    float $timeout = 7200.0,
): void {
    $deadline = microtime(true) + $timeout;
    while ($process->isRunning()) {
        $observe(schedulerAcceptanceAttempts($paths, $run));
        if (microtime(true) >= $deadline) {
            $process->stop(30, SIGKILL);
            throw new RuntimeException('The scenario scheduler acceptance process timed out.');
        }
        usleep(250_000);
    }
    $process->wait();
}

/** @param array<string, mixed> $first @param array<string, mixed> $second */
function schedulerAcceptanceIntervalsOverlap(array $first, array $second): bool
{
    $firstStarted = $first['started_at'] ?? null;
    $firstFinished = $first['finished_at'] ?? null;
    $secondStarted = $second['started_at'] ?? null;
    $secondFinished = $second['finished_at'] ?? null;
    if (! is_string($firstStarted) || ! is_string($firstFinished)
        || ! is_string($secondStarted) || ! is_string($secondFinished)) {
        return false;
    }

    return $firstStarted < $secondFinished && $secondStarted < $firstFinished;
}

/** @return array{contents:string,instances:list<string>} */
function schedulerAcceptanceUnrelatedTopology(): array
{
    $path = dirname(__DIR__, 4).'/.e2e/topology.json';
    $contents = file_get_contents($path);
    if (! is_string($contents)) {
        throw new RuntimeException('This observation requires an acquired discovery topology.');
    }
    $topology = readSchedulerAcceptanceJson($path);
    $instances = $topology['instances'] ?? null;
    if (! is_array($instances) || $instances === []
        || ! array_all($instances, static fn (mixed $instance): bool => is_string($instance))) {
        throw new RuntimeException('The acquired discovery topology inventory is invalid.');
    }

    return ['contents' => $contents, 'instances' => array_values($instances)];
}

it('scenario-worker-capacity', function (): void {
    $paths = $this->app->make(StatePaths::class);
    $host = $this->app->make(IncusHost::class);
    $maxVms = (int) config('e2e.incus.max_vms');
    $beforeRuns = schedulerAcceptanceRunIds($paths);
    $baselineVms = count($host->harnessInstanceMetadata());
    $process = startSchedulerAcceptanceProcess([
        'cold-construction-cleanup',
        'snapshot-lifecycle',
        'snapshot-extension',
    ], 2);
    $run = awaitSchedulerAcceptanceRun($paths, $beforeRuns);
    $peakWorkers = 0;
    $peakVms = $baselineVms;
    $stoppedAfterAdmission = false;

    observeSchedulerAcceptanceProcess(
        $process,
        $paths,
        $run,
        function (array $attempts) use (&$peakWorkers, &$peakVms, &$stoppedAfterAdmission, $host, $process): void {
            $peakWorkers = max($peakWorkers, count(array_filter(
                $attempts,
                static fn (array $attempt): bool => ! isset($attempt['finished_at']),
            )));
            $peakVms = max($peakVms, count($host->harnessInstanceMetadata()));
            if (! $stoppedAfterAdmission && count($attempts) === 3 && array_all(
                $attempts,
                static fn (array $attempt): bool => isset($attempt['construction_inputs']['recipe'])
                    || ($attempt['construction_inputs']['construction'] ?? null) !== null,
            )) {
                $stoppedAfterAdmission = true;
                $process->signal(SIGTERM);
            }
        },
    );
    $aggregate = awaitSchedulerAcceptanceAggregate($paths, $run);
    $attempts = schedulerAcceptanceAttempts($paths, $run);

    expect($stoppedAfterAdmission)->toBeTrue();
    expect($process->getExitCode())->not->toBe(0);
    expect($peakWorkers)->toBe(2);
    expect($peakVms)->toBeGreaterThan($baselineVms)->toBeLessThanOrEqual($maxVms);
    expect(array_column($aggregate['results'], 'scenario_id'))->toBe([
        'cold-construction-cleanup',
        'snapshot-lifecycle',
        'snapshot-extension',
    ]);
    expect($aggregate['status'])->toBe('failed');
    expect($aggregate['results'])->toHaveCount(3);
    expect(array_map(
        static fn (array $attempt): int => count($attempt['recipe']['nodes'] ?? []),
        $attempts,
    ))->toEqualCanonicalizing([4, 3, 4]);
    expect(array_all(
        $attempts,
        static fn (array $attempt): bool => count(
            $attempt['construction_inputs']['construction']['nodes']
                ?? $attempt['construction_inputs']['recipe']['nodes']
                ?? [],
        ) === count($attempt['recipe']['nodes'] ?? []),
    ))->toBeTrue();
    expect(array_all($aggregate['results'], static fn (array $result): bool => is_string($result['cleanup']['recovery_command'] ?? null)
        && (($result['cleanup']['remaining'] ?? []) === []
            || ($result['cleanup']['refused'] ?? []) !== [])))->toBeTrue();
});

it('scenario-worker-isolation', function (): void {
    $paths = $this->app->make(StatePaths::class);
    $beforeRuns = schedulerAcceptanceRunIds($paths);
    $process = startSchedulerAcceptanceProcess([
        'snapshot-lifecycle',
        'snapshot-isolation',
    ], 2);
    $run = awaitSchedulerAcceptanceRun($paths, $beforeRuns);
    $stoppedAfterOverlap = false;

    observeSchedulerAcceptanceProcess(
        $process,
        $paths,
        $run,
        function (array $attempts) use (&$stoppedAfterOverlap, $process): void {
            if (! $stoppedAfterOverlap && count($attempts) === 2 && array_all(
                $attempts,
                static fn (array $attempt): bool => isset($attempt['phase_timings']['candidate-identity']),
            )) {
                $stoppedAfterOverlap = true;
                $process->signal(SIGTERM);
            }
        },
    );
    $aggregate = awaitSchedulerAcceptanceAggregate($paths, $run);
    $attempts = schedulerAcceptanceAttempts($paths, $run);
    $results = $aggregate['results'] ?? [];

    expect($stoppedAfterOverlap)->toBeTrue();
    expect($process->getExitCode())->not->toBe(0);
    expect($results)->toHaveCount(2);
    expect(array_unique(array_column($attempts, 'attempt_id')))->toHaveCount(2);
    expect(array_unique(array_column($attempts, 'operation_id')))->toHaveCount(2);
    expect(array_unique(array_column($attempts, 'network')))->toHaveCount(2);
    expect(array_unique(array_merge(...array_column($attempts, 'instances'))))->toHaveCount(6);
    expect(array_all($attempts, static fn (array $attempt): bool => ($attempt['construction_inputs']['candidate_sync']['candidate_sha'] ?? null)
            === ($attempt['candidate_sha'] ?? null)))->toBeTrue();
    expect(array_all($results, static fn (array $result): bool => is_string($result['cleanup']['recovery_command'] ?? null)
        && (($result['cleanup']['remaining'] ?? []) === []
            || ($result['cleanup']['refused'] ?? []) !== [])))->toBeTrue();

    $overlap = false;
    foreach (($attempts[0]['phase_timings'] ?? []) as $firstName => $firstTiming) {
        if (in_array($firstName, ['resolve-generation', 'construct', 'cleanup'], true) || ! is_array($firstTiming)) {
            continue;
        }
        foreach (($attempts[1]['phase_timings'] ?? []) as $secondName => $secondTiming) {
            if (in_array($secondName, ['resolve-generation', 'construct', 'cleanup'], true) || ! is_array($secondTiming)) {
                continue;
            }
            if (schedulerAcceptanceIntervalsOverlap($firstTiming, $secondTiming)) {
                $overlap = true;
                break 2;
            }
        }
    }
    expect($overlap)->toBeTrue();
});

it('scenario-worker-interruption', function (): void {
    $paths = $this->app->make(StatePaths::class);
    $host = $this->app->make(IncusHost::class);
    $unrelated = schedulerAcceptanceUnrelatedTopology();
    $beforeRuns = schedulerAcceptanceRunIds($paths);
    $process = startSchedulerAcceptanceProcess([
        'cold-four-node',
        'snapshot-lifecycle',
        'snapshot-extension',
    ], 2);
    $run = awaitSchedulerAcceptanceRun($paths, $beforeRuns);
    $deadline = microtime(true) + 1800;
    do {
        $attempts = schedulerAcceptanceAttempts($paths, $run);
        $scenarioVms = array_filter(
            $host->harnessInstanceMetadata(),
            static fn (array $metadata): bool => ($metadata['user.orbit.e2e.run'] ?? null) === $run,
        );
        if (count($attempts) === 2 && $scenarioVms !== []) {
            break;
        }
        if (! $process->isRunning()) {
            throw new RuntimeException('The scheduler stopped before the interruption observation.');
        }
        usleep(250_000);
    } while (microtime(true) < $deadline);
    if (count($attempts) !== 2 || $scenarioVms === []) {
        throw new RuntimeException('The scheduler did not expose active and queued work before interruption.');
    }

    $process->signal(SIGINT);
    observeSchedulerAcceptanceProcess($process, $paths, $run, static function (): void {}, 600);
    $aggregate = awaitSchedulerAcceptanceAggregate($paths, $run);
    $results = $aggregate['results'] ?? [];

    expect($process->getExitCode())->not->toBe(0);
    expect(array_column($results, 'scenario_id'))->toBe([
        'cold-four-node',
        'snapshot-lifecycle',
        'snapshot-extension',
    ]);
    expect(array_column($results, 'status'))->toBe([
        'infrastructure-error',
        'infrastructure-error',
        'infrastructure-error',
    ]);
    expect($results[2]['diagnostics'][0] ?? null)->toContain('was not started because the run was interrupted');
    expect(array_all(array_slice($results, 0, 2), static fn (array $result): bool => is_string($result['cleanup']['recovery_command'] ?? null)
        && (($result['cleanup']['remaining'] ?? []) === []
            || ($result['cleanup']['refused'] ?? []) !== [])))->toBeTrue();
    expect(file_get_contents(dirname(__DIR__, 4).'/.e2e/topology.json'))->toBe($unrelated['contents']);
    expect(array_all(
        $unrelated['instances'],
        static fn (string $instance): bool => $host->instance($instance) !== null,
    ))->toBeTrue();
});
