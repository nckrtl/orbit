<?php

declare(strict_types=1);

use App\E2E\ColdTopologyConstructor;
use App\E2E\Git\GitRepository;
use App\E2E\ScenarioCatalog;
use App\E2E\ScenarioPestProcess;
use App\E2E\ScenarioRecovery;
use App\E2E\ScenarioSuiteRunner;
use App\E2E\ScenarioWorkerProcess;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\ScenarioRunStore;
use App\E2E\State\SecretRedactor;
use App\E2E\State\StatePaths;
use App\E2E\Value\AttemptId;
use App\E2E\Value\ColdTopologyCleanupResult;
use App\E2E\Value\OperationId;
use App\E2E\Value\ScenarioAction;
use App\E2E\Value\ScenarioDefinition;
use App\E2E\Value\ScenarioId;
use App\E2E\Value\ScenarioProcessResult;
use App\E2E\Value\ScenarioResult;
use App\E2E\Value\ScenarioRunId;
use App\E2E\Value\ScenarioStatus;
use App\E2E\Value\TopologyEndState;
use App\E2E\Value\TopologyExtension;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologyTarget;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Facade;

beforeEach(function (): void {
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
});

function schedulerDefinition(string $id, string $lane, bool $extended = false): ScenarioDefinition
{
    $recipe = $extended ? TopologyRecipe::extendedAppProd() : TopologyRecipe::registered();

    return new ScenarioDefinition(
        new ScenarioId($id),
        $lane,
        $recipe,
        $lane === 'snapshot'
            ? [
                new ScenarioAction('setup', 'prepare', 60),
                new ScenarioAction('exercise', 'run', 60),
                new ScenarioAction('assertion', 'verify', 60),
            ]
            : [new ScenarioAction('setup', 'construct', 60)],
        ['apps/e2e/resources/guest/prepare-node.sh' => str_repeat('a', 64)],
        TopologyEndState::complete($recipe),
        false,
        "{$id} filter",
        extension: $extended ? TopologyExtension::AppProd : null,
    );
}

/** @param array<string, mixed> $cleanup */
function schedulerResult(
    ScenarioDefinition $definition,
    string $candidate,
    ScenarioRunId $run,
    AttemptId $attempt,
    ScenarioStatus $primary = ScenarioStatus::Passed,
    ?ScenarioStatus $effective = null,
    array $cleanup = [],
): ScenarioResult {
    return new ScenarioResult(
        $candidate,
        $run,
        $definition->id,
        $attempt,
        $definition->lane,
        $primary,
        $effective ?? $primary,
        $definition->normalized(),
        $definition->fingerprint(),
        $definition->recipe->fingerprint(),
        [['name' => 'flow', 'outcome' => $primary->value]],
        ['flow' => ['duration_ms' => 1]],
        null,
        [],
        $cleanup === []
            ? [
                'removed' => [],
                'absent' => [],
                'refused' => [],
                'remaining' => [],
                'recovery_command' => "bin/e2e-scenarios cleanup {$run->value} {$definition->id->value} {$attempt->value}",
            ]
            : $cleanup,
        '2026-09-11T08:00:00.000000+00:00',
        '2026-09-11T08:00:01.000000+00:00',
    );
}

/** @return array{ScenarioSuiteRunner,ScenarioRunStore,string,string} */
function schedulerSuite(array $definitions, Closure $processFactory, ?Closure $cleanup = null): array
{
    $repositoryRoot = dirname(__DIR__, 5);
    $candidate = (new GitRepository($repositoryRoot))->commit();
    $stateRoot = temporaryPath('scenario-scheduler-', 5);
    $runs = new ScenarioRunStore(new AtomicJsonStore(new StatePaths($stateRoot)));
    $catalog = new ScenarioCatalog(
        new GitRepository($repositoryRoot),
        static fn (): array => $definitions,
    );
    $process = new ScenarioPestProcess(dirname(__DIR__, 3), $processFactory);
    $constructor = (new ReflectionClass(ColdTopologyConstructor::class))->newInstanceWithoutConstructor();
    $recovery = new ScenarioRecovery(
        $runs,
        $constructor,
        $cleanup ?? static fn (): ColdTopologyCleanupResult => new ColdTopologyCleanupResult([], [], []),
    );

    return [
        new ScenarioSuiteRunner($catalog, $runs, $process, $recovery, new SecretRedactor),
        $runs,
        $candidate,
        $stateRoot,
    ];
}

it('refuses a non-positive worker count before creating run state', function (): void {
    $definitions = [schedulerDefinition('cold-first', 'cold')];
    [$runner, , $candidate, $stateRoot] = schedulerSuite(
        $definitions,
        static fn (): never => throw new RuntimeException('A worker must not start.'),
    );

    expect(fn () => $runner->runAll($candidate, dirname(__DIR__, 5), dirname(__DIR__, 5), [], 0))
        ->toThrow(InvalidArgumentException::class, 'worker count must be a positive integer');
    expect(is_dir("{$stateRoot}/scenarios/runs"))->toBeFalse();
});

it('bounds mixed-lane workers and preserves selection order across out-of-order completion', function (): void {
    $definitions = [
        schedulerDefinition('cold-first', 'cold'),
        schedulerDefinition('snapshot-second', 'snapshot'),
        schedulerDefinition('snapshot-extended', 'snapshot', true),
    ];
    $active = 0;
    $peak = 0;
    $started = [];
    $completed = [];
    $identities = [];
    $runs = null;
    [$runner, $runStore, $candidate] = schedulerSuite(
        $definitions,
        function (
            ScenarioDefinition $definition,
            string $processCandidate,
            ScenarioRunId $run,
            AttemptId $attempt,
            OperationId $operation,
        ) use (&$active, &$peak, &$started, &$completed, &$identities, &$runs): ScenarioWorkerProcess {
            $active++;
            $peak = max($peak, $active);
            $started[] = $definition->id->value;
            $identities[] = [
                'attempt' => $attempt->value,
                'operation' => $operation->value,
                'network' => TopologyTarget::disposableScenario($run, $definition->id, $attempt, $definition->recipe)->network(),
                'vms' => count($definition->recipe->nodes),
            ];
            $polls = $definition->id->value === 'cold-first' ? 2 : 0;

            return new ScenarioWorkerProcess(
                function () use (
                    &$polls,
                    &$active,
                    &$completed,
                    &$runs,
                    $definition,
                    $processCandidate,
                    $run,
                    $attempt,
                ): ?ScenarioProcessResult {
                    if ($polls > 0) {
                        $polls--;

                        return null;
                    }
                    $active--;
                    $completed[] = $definition->id->value;
                    assert($runs instanceof ScenarioRunStore);
                    $runs->writeResult(schedulerResult($definition, $processCandidate, $run, $attempt));

                    return new ScenarioProcessResult(0, "{$definition->id->value}\n");
                },
                static function (int $signal): void {},
                static fn (): ScenarioProcessResult => new ScenarioProcessResult(137, 'forced'),
            );
        },
    );
    $runs = $runStore;

    $aggregate = $runner->runAll(
        $candidate,
        dirname(__DIR__, 5),
        dirname(__DIR__, 5),
        ['snapshot-extended', 'cold-first', 'snapshot-second'],
        2,
    );

    expect($peak)->toBe(2);
    expect($started)->toBe(['snapshot-extended', 'cold-first', 'snapshot-second']);
    expect($completed)->toBe(['snapshot-extended', 'snapshot-second', 'cold-first']);
    expect(array_column($aggregate->toArray()['results'], 'scenario_id'))
        ->toBe(['snapshot-extended', 'cold-first', 'snapshot-second']);
    expect(array_unique(array_column($identities, 'attempt')))->toHaveCount(3);
    expect(array_unique(array_column($identities, 'operation')))->toHaveCount(3);
    expect(array_unique(array_column($identities, 'network')))->toHaveCount(3);
    expect(array_column($identities, 'vms'))->toBe([4, 3, 3]);
});

it('continues after product failure and cleanup refusal before writing every outcome', function (): void {
    $definitions = [
        schedulerDefinition('failed-flow', 'cold'),
        schedulerDefinition('cleanup-refused', 'snapshot'),
        schedulerDefinition('passing-flow', 'cold'),
    ];
    $started = [];
    $runs = null;
    [$runner, $runStore, $candidate] = schedulerSuite(
        $definitions,
        function (
            ScenarioDefinition $definition,
            string $processCandidate,
            ScenarioRunId $run,
            AttemptId $attempt,
        ) use (&$started, &$runs): ScenarioProcessResult {
            $started[] = $definition->id->value;
            [$primary, $effective, $cleanup] = match ($definition->id->value) {
                'failed-flow' => [ScenarioStatus::Failed, ScenarioStatus::Failed, []],
                'cleanup-refused' => [
                    ScenarioStatus::Passed,
                    ScenarioStatus::InfrastructureError,
                    [
                        'removed' => [],
                        'absent' => [],
                        'refused' => ['owned VM changed before cleanup'],
                        'remaining' => ['exact-vm'],
                        'recovery_command' => 'bin/e2e-scenarios cleanup retained',
                    ],
                ],
                default => [ScenarioStatus::Passed, ScenarioStatus::Passed, []],
            };
            assert($runs instanceof ScenarioRunStore);
            $runs->writeResult(schedulerResult(
                $definition,
                $processCandidate,
                $run,
                $attempt,
                $primary,
                $effective,
                $cleanup,
            ));

            return new ScenarioProcessResult($effective === ScenarioStatus::Passed ? 0 : 1, 'finished');
        },
    );
    $runs = $runStore;

    $aggregate = $runner->runAll($candidate, dirname(__DIR__, 5), dirname(__DIR__, 5), [], 2);

    expect($started)->toBe(['failed-flow', 'cleanup-refused', 'passing-flow']);
    expect(array_column($aggregate->toArray()['results'], 'primary_status'))
        ->toBe(['failed', 'passed', 'passed']);
    expect(array_column($aggregate->toArray()['results'], 'status'))
        ->toBe(['failed', 'infrastructure-error', 'passed']);
    expect($aggregate->results[1]->cleanup['remaining'])->toBe(['exact-vm']);
    expect($aggregate->successful())->toBeFalse();
});

it('cleans active attempts and reports queued flows when interrupted', function (): void {
    $definitions = [
        schedulerDefinition('active-first', 'cold'),
        schedulerDefinition('active-second', 'snapshot'),
        schedulerDefinition('queued-third', 'snapshot', true),
    ];
    $started = [];
    $signals = [];
    $recoveries = [];
    $sent = false;
    $unrelated = temporaryFile('scenario-unrelated-');
    file_put_contents($unrelated, 'preserve');
    [$runner, , $candidate] = schedulerSuite(
        $definitions,
        function (ScenarioDefinition $definition) use (&$started, &$signals, &$sent): ScenarioWorkerProcess {
            $started[] = $definition->id->value;
            $signaled = false;

            return new ScenarioWorkerProcess(
                function () use (&$sent, &$signaled): ?ScenarioProcessResult {
                    if (! $sent) {
                        $sent = true;
                        posix_kill(getmypid(), SIGINT);

                        return null;
                    }

                    return $signaled ? new ScenarioProcessResult(130, 'interrupted worker') : null;
                },
                function (int $signal) use (&$signals, &$signaled, $definition): void {
                    $signals[$definition->id->value] = $signal;
                    $signaled = true;
                },
                static fn (): ScenarioProcessResult => new ScenarioProcessResult(137, 'forced worker stop'),
            );
        },
        function (ScenarioRunId $run, ScenarioId $scenario, AttemptId $attempt) use (&$recoveries): ColdTopologyCleanupResult {
            $recoveries[] = $scenario->value;

            return new ColdTopologyCleanupResult(
                ["removed-{$scenario->value}"],
                [],
                [],
                [],
                "bin/e2e-scenarios cleanup {$run->value} {$scenario->value} {$attempt->value}",
            );
        },
    );

    $aggregate = $runner->runAll($candidate, dirname(__DIR__, 5), dirname(__DIR__, 5), [], 2);

    expect($started)->toBe(['active-first', 'active-second']);
    expect($signals)->toBe(['active-first' => SIGINT, 'active-second' => SIGINT]);
    expect($recoveries)->toBe(['active-first', 'active-second', 'queued-third']);
    expect(array_column($aggregate->toArray()['results'], 'scenario_id'))
        ->toBe(['active-first', 'active-second', 'queued-third']);
    expect(array_column($aggregate->toArray()['results'], 'status'))
        ->toBe(['infrastructure-error', 'infrastructure-error', 'infrastructure-error']);
    expect($aggregate->results[2]->diagnostics[0])
        ->toContain('was not started because the run was interrupted by signal '.SIGINT);
    expect($aggregate->results[0]->cleanup['removed'])->toBe(['removed-active-first']);
    expect(file_get_contents($unrelated))->toBe('preserve');
});

it('records a refused parent recovery and continues another worker', function (): void {
    $definitions = [
        schedulerDefinition('recovery-refused', 'cold'),
        schedulerDefinition('still-runs', 'snapshot'),
    ];
    $runs = null;
    [$runner, $runStore, $candidate] = schedulerSuite(
        $definitions,
        function (
            ScenarioDefinition $definition,
            string $processCandidate,
            ScenarioRunId $run,
            AttemptId $attempt,
        ) use (&$runs): ScenarioProcessResult {
            if ($definition->id->value === 'still-runs') {
                assert($runs instanceof ScenarioRunStore);
                $runs->writeResult(schedulerResult($definition, $processCandidate, $run, $attempt));

                return new ScenarioProcessResult(0, 'passed');
            }

            return new ScenarioProcessResult(70, 'worker exited without result');
        },
        static function (ScenarioRunId $run, ScenarioId $scenario): ColdTopologyCleanupResult {
            if ($scenario->value === 'recovery-refused') {
                throw new RuntimeException('exact cleanup ownership changed');
            }

            return new ColdTopologyCleanupResult([], [], []);
        },
    );
    $runs = $runStore;

    $aggregate = $runner->runAll($candidate, dirname(__DIR__, 5), dirname(__DIR__, 5), [], 1);

    expect(array_column($aggregate->toArray()['results'], 'status'))
        ->toBe(['infrastructure-error', 'passed']);
    expect($aggregate->results[0]->cleanup['refused'])->toBe(['exact cleanup ownership changed']);
    expect($aggregate->results[0]->cleanup['remaining'])->toHaveCount(4);
    expect($aggregate->results[0]->diagnostics)->toContain('exact cleanup ownership changed');
});
