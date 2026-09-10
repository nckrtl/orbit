<?php

declare(strict_types=1);

use App\E2E\ColdTopologyConstructor;
use App\E2E\Git\GitRepository;
use App\E2E\HostCapacity;
use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\IssueTopologyConstructor;
use App\E2E\PreparedStateFingerprint;
use App\E2E\PromotedTopologySnapshotResolver;
use App\E2E\ScenarioCatalog;
use App\E2E\ScenarioRecovery;
use App\E2E\SnapshotScenarioRunner;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\ScenarioRunStore;
use App\E2E\State\SecretRedactor;
use App\E2E\State\StatePaths;
use App\E2E\TopologyConverger;
use App\E2E\TopologySnapshotAvailability;
use App\E2E\TopologySnapshotManifestStore;
use App\E2E\TopologyVerifier;
use App\E2E\Value\AttemptId;
use App\E2E\Value\ColdTopologyCleanupResult;
use App\E2E\Value\OperationId;
use App\E2E\Value\ScenarioAction;
use App\E2E\Value\ScenarioDefinition;
use App\E2E\Value\ScenarioId;
use App\E2E\Value\ScenarioResult;
use App\E2E\Value\ScenarioRunId;
use App\E2E\Value\ScenarioStatus;
use App\E2E\Value\TopologyConstructionInputs;
use App\E2E\Value\TopologyEndState;
use App\E2E\Value\TopologyExtension;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologySnapshotIdentity;
use App\E2E\Value\TopologyTarget;
use App\E2E\WorktreeSynchronizer;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

require_once __DIR__.'/Support/TopologyFixtures.php';

beforeEach(function (): void {
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
});

function snapshotRunnerDefinition(string $id = 'snapshot-lifecycle'): ScenarioDefinition
{
    $extension = $id === 'snapshot-extension' ? TopologyExtension::AppProd : null;
    $recipe = $extension?->recipe() ?? TopologyRecipe::registered();
    $exercise = match ($id) {
        'snapshot-isolation' => 'isolation',
        'snapshot-extension' => 'extension',
        default => 'lifecycle',
    };

    return new ScenarioDefinition(
        new ScenarioId($id),
        'snapshot',
        $recipe,
        [
            new ScenarioAction('setup', 'prepare', 3600),
            new ScenarioAction('exercise', $exercise, 900),
            new ScenarioAction('assertion', 'verify', 900),
        ],
        ['apps/e2e/resources/guest/prepare-node.sh' => str_repeat('a', 64)],
        TopologyEndState::complete($recipe),
        false,
        'snapshot scenario fixture',
        extension: $extension,
    );
}

/**
 * @return array{
 *     runner:SnapshotScenarioRunner,
 *     runs:ScenarioRunStore,
 *     repository:string,
 *     candidate:string,
 *     definition:ScenarioDefinition,
 *     run:ScenarioRunId,
 *     attempt:AttemptId,
 *     operation:OperationId,
 *     target:TopologyTarget,
 *     manifests:TopologySnapshotManifestStore,
 *     events:list<array<array-key, mixed>>
 * }
 */
function snapshotRunnerFixture(
    bool $promote = true,
    string $scenario = 'snapshot-lifecycle',
    bool $failExercise = false,
): array {
    $root = preparedTopologyRepository();
    $repository = pinnedFeatureWorktree($root, $scenario);
    $candidate = new GitRepository($repository)->commit();
    $paths = new StatePaths(temporaryPath('snapshot-scenario-state-', 4));
    if ($promote) {
        promoteDiscoveryGeneration($root, $paths);
    }
    $host = new IncusHost(pool: 'default');
    $networks = new IncusNetworkLifecycle($host);
    $store = new AtomicJsonStore($paths);
    $runs = new ScenarioRunStore($store);
    $manifests = new TopologySnapshotManifestStore($store, $paths, $host);
    $operation = new OperationId(str_repeat('f', 32));
    $definition = snapshotRunnerDefinition($scenario);
    $run = new ScenarioRunId(str_repeat('b', 32));
    $attempt = new AttemptId(str_repeat('c', 32));
    $target = TopologyTarget::disposableScenario($run, $definition->id, $attempt, $definition->recipe);
    $runs->beginRun($run, $candidate, [$definition], '2026-09-10T12:00:00.000000+00:00');
    $runs->beginAttempt(
        $run,
        $definition,
        $attempt,
        $operation,
        $target,
        $candidate,
        '2026-09-10T12:00:00.000000+00:00',
    );
    $synchronizer = new WorktreeSynchronizer($host, $repository, $operation);
    $capacity = new HostCapacity($host, 24);
    $constructor = new IssueTopologyConstructor(
        $host,
        $networks,
        $capacity,
        $paths,
        $operation,
        $manifests,
        TopologySnapshotIdentity::primary(),
    );
    $cold = new ColdTopologyConstructor(
        $host,
        $networks,
        $synchronizer,
        new TopologyConverger($host, 0),
        $capacity,
        $paths,
    );
    $recovery = new ScenarioRecovery(
        $runs,
        $cold,
        static fn (): ColdTopologyCleanupResult => new ColdTopologyCleanupResult(
            [$target->instance('gateway'), $target->network()],
            [],
            [],
        ),
    );
    $runner = new SnapshotScenarioRunner(
        new ScenarioCatalog(new GitRepository($repository), static fn (): array => [$definition]),
        $runs,
        new PromotedTopologySnapshotResolver(
            new PreparedStateFingerprint(new GitRepository($root)),
            $manifests,
            new TopologySnapshotAvailability($host, TopologySnapshotIdentity::primary()),
        ),
        $constructor,
        $host,
        $synchronizer,
        new TopologyConverger($host, 0),
        new TopologyVerifier($host, 1, 0),
        $recovery,
        $operation,
        new SecretRedactor,
    );
    $events = [];
    if ($promote) {
        $candidateTree = (new GitRepository($repository))->tree($candidate);
        fakePinnedWorktreeProcesses(
            $target,
            $events,
            guestOverride: static function (array $guest) use ($candidate, $candidateTree, $failExercise) {
                if ($guest === ['git', '-C', '/home/orbit/orbit', 'rev-parse', '--verify', 'HEAD^{commit}']) {
                    return Process::result($candidate."\n");
                }
                if ($guest === ['git', '-C', '/home/orbit/orbit', 'rev-parse', '--verify', 'HEAD^{tree}']) {
                    return Process::result($candidateTree."\n");
                }
                if ($guest === ['git', '-C', '/home/orbit/orbit', 'status', '--porcelain=v1', '--untracked-files=all']) {
                    return Process::result();
                }
                if ($failExercise && in_array('timeout', $guest, true) && end($guest) === 'true') {
                    return Process::result('', 'injected exercise failure', 1);
                }

                return null;
            },
            operationId: $operation->value,
        );
    }

    return compact(
        'runner',
        'runs',
        'repository',
        'candidate',
        'definition',
        'run',
        'attempt',
        'operation',
        'target',
        'manifests',
        'events',
    );
}

/** @param array<string, mixed> $fixture */
function executeSnapshotRunner(array $fixture): ScenarioResult
{
    $environment = [
        'ORBIT_SCENARIO_CANDIDATE_SHA' => $fixture['candidate'],
        'ORBIT_SCENARIO_REPOSITORY' => $fixture['repository'],
        'ORBIT_SCENARIO_RUN_ID' => $fixture['run']->value,
        'ORBIT_SCENARIO_ID' => $fixture['definition']->id->value,
        'ORBIT_SCENARIO_ATTEMPT_ID' => $fixture['attempt']->value,
        'ORBIT_SCENARIO_OPERATION_ID' => $fixture['operation']->value,
    ];
    foreach ($environment as $name => $value) {
        putenv("{$name}={$value}");
    }

    try {
        return $fixture['runner']->executeFromEnvironment($fixture['definition']->id->value);
    } finally {
        foreach (array_keys($environment) as $name) {
            putenv($name);
        }
    }
}

it('clones the recorded generation and converges the exact candidate before exercise', function (): void {
    $fixture = snapshotRunnerFixture();
    $promotion = $fixture['manifests']->promoted()?->toArray();

    $result = executeSnapshotRunner($fixture);

    $state = $fixture['runs']->attempt($fixture['run'], $fixture['definition']->id, $fixture['attempt']);
    $phases = array_keys($state['phase_timings']);
    expect($result->diagnostics)->toBe([]);
    expect($result->status)->toBe(ScenarioStatus::Passed);
    expect(array_column($result->actions, 'phase'))->toBe(['setup', 'exercise', 'assertion']);
    expect(array_column($result->actions, 'outcome'))->toBe(['passed', 'passed', 'passed']);
    expect($result->actions[1]['name'])->toBe('lifecycle');
    expect($phases)->toBe([
        'resolve-generation',
        'construct',
        'start-instances',
        'prepare-host-state',
        'synchronize-candidate',
        'candidate-identity',
        'converge',
        'converged-candidate-identity',
        'readiness',
        'verify',
        'cleanup',
    ]);
    expect($state['construction_inputs']['source_generation'])->toBe($promotion);
    expect($state['construction_inputs']['candidate_sync']['candidate_sha'])->toBe($fixture['candidate']);
    expect($state['construction_inputs']['construction']['source_generation'])
        ->toBe($promotion['id']);
    expect($fixture['manifests']->promoted()?->toArray())->toBe($promotion);
});

it('skips exercise and records infrastructure diagnostics when no generation is promoted', function (): void {
    $fixture = snapshotRunnerFixture(false);

    $result = executeSnapshotRunner($fixture);

    expect($result->primaryStatus)->toBe(ScenarioStatus::InfrastructureError);
    expect($result->status)->toBe(ScenarioStatus::InfrastructureError);
    expect($result->diagnostics)->toBe(['No promoted topology snapshot generation is available.']);
    expect(array_column($result->actions, 'phase'))->toBe(['setup']);
    expect($result->cleanup['remaining'])->toBe([]);
});

it('uses an absence-checked attempt marker for each isolation exercise', function (): void {
    $fixture = snapshotRunnerFixture(scenario: 'snapshot-isolation');

    $result = executeSnapshotRunner($fixture);

    expect($result->diagnostics)->toBe([]);
    expect($result->status)->toBe(ScenarioStatus::Passed);
    expect($result->actions[1]['evidence'])->toBe('fresh clone did not contain the prior attempt marker');
});

it('records a product failure when bounded exercise fails after preparation', function (): void {
    $fixture = snapshotRunnerFixture(failExercise: true);

    $result = executeSnapshotRunner($fixture);

    expect($result->primaryStatus)->toBe(ScenarioStatus::Failed);
    expect($result->status)->toBe(ScenarioStatus::Failed);
    expect($result->actions[1]['outcome'])->toBe('failed');
    expect($result->diagnostics[0])->toContain('failed with exit code 1');
    expect($result->cleanup['remaining'])->toBe([]);
});

it('accepts a fully recorded extension physical identity and capacity reservation', function (): void {
    $fixture = snapshotRunnerFixture(false, 'snapshot-extension');
    $construction = TopologyConstructionInputs::forGeneration(
        $fixture['target'],
        'fixture-generation',
        2,
        TopologyExtension::AppProd,
        str_repeat('a', 64),
    );
    $metadata = [
        'user.orbit.e2e.owner' => 'orbit-e2e',
        'user.orbit.e2e.issue' => 'SCN-1',
        'user.orbit.e2e.run' => $fixture['run']->value,
        'user.orbit.e2e.scenario' => $fixture['definition']->id->value,
        'user.orbit.e2e.attempt' => $fixture['attempt']->value,
        'user.orbit.e2e.operation' => $fixture['operation']->value,
    ];
    Process::fake(static function (PendingProcess $process) use ($fixture, $metadata) {
        if (($process->command[3] ?? null) === 'list') {
            return Process::result(topologyVmJson(
                $fixture['target']->instance('app-prod-2'),
                $metadata,
                $fixture['target']->network(),
                true,
                $fixture['target']->recipe,
            ));
        }

        return Process::result();
    });
    $method = new ReflectionMethod($fixture['runner'], 'assertExtension');

    $method->invoke(
        $fixture['runner'],
        $fixture['definition'],
        $fixture['target'],
        $construction,
        $fixture['operation'],
    );

    expect($construction->slot)->toBe(2);
    expect($construction->imageAlias)->toBe(TopologyRecipe::BASE_IMAGE);
    expect($construction->imageFingerprint)->toBe(str_repeat('a', 64));
});

it('refuses an extension whose physical identity does not match its attempt record', function (): void {
    $fixture = snapshotRunnerFixture(false, 'snapshot-extension');
    $construction = TopologyConstructionInputs::forGeneration(
        $fixture['target'],
        'fixture-generation',
        2,
        TopologyExtension::AppProd,
        str_repeat('a', 64),
    );
    Process::fake([
        '*' => Process::result('[]'),
    ]);
    $method = new ReflectionMethod($fixture['runner'], 'assertExtension');

    expect(fn () => $method->invoke(
        $fixture['runner'],
        $fixture['definition'],
        $fixture['target'],
        $construction,
        $fixture['operation'],
    ))->toThrow(RuntimeException::class, 'physical identity is invalid');
});
