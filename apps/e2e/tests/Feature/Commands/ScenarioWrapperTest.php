<?php

declare(strict_types=1);

use App\E2E\ColdTopologyConstructor;
use App\E2E\Git\GitRepository;
use App\E2E\ScenarioCatalog;
use App\E2E\ScenarioPestProcess;
use App\E2E\ScenarioRecovery;
use App\E2E\ScenarioSuiteRunner;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\ScenarioRunStore;
use App\E2E\State\SecretRedactor;
use App\E2E\State\StatePaths;
use App\E2E\Value\AttemptId;
use App\E2E\Value\ColdTopologyCleanupResult;
use App\E2E\Value\ScenarioAction;
use App\E2E\Value\ScenarioDefinition;
use App\E2E\Value\ScenarioId;
use App\E2E\Value\ScenarioProcessResult;
use App\E2E\Value\ScenarioResult;
use App\E2E\Value\ScenarioRunId;
use App\E2E\Value\ScenarioStatus;
use App\E2E\Value\TopologyEndState;
use App\E2E\Value\TopologyRecipe;
use Symfony\Component\Process\Process;

$wrapper = dirname(__DIR__, 5).'/bin/e2e-scenarios';

/** @return array{environment:array<string, string>, head:string, primary_root:string} */
function scenarioWrapperFixture(): array
{
    $root = temporaryPath('orbit-scenario-wrapper-', 5);
    $bin = "{$root}/bin";
    $commonGit = "{$root}/repository/.git";
    mkdir($bin, 0o700, true);
    mkdir($commonGit, 0o700, true);
    file_put_contents("{$bin}/git", <<<'BASH'
        #!/usr/bin/env bash
        set -euo pipefail
        case "$*" in
          *' rev-parse --verify HEAD^{commit}') printf '%s\n' "$SCENARIO_TEST_HEAD" ;;
          *' status --porcelain --untracked-files=all -- . :!.e2e') ;;
          *' rev-parse --path-format=absolute --git-common-dir') printf '%s\n' "$SCENARIO_TEST_COMMON_GIT" ;;
          *) exit 64 ;;
        esac
        BASH);
    file_put_contents("{$bin}/composer", <<<'BASH'
        #!/usr/bin/env bash
        set -euo pipefail
        printf 'candidate=%s\nrepository=%s\nprimary-root=%s\narguments=%s\n' \
          "${ORBIT_SCENARIO_CANDIDATE_SHA:-none}" \
          "$ORBIT_SCENARIO_REPOSITORY" \
          "$ORBIT_SCENARIO_PRIMARY_ROOT" \
          "$*"
        BASH);
    chmod("{$bin}/git", 0o700);
    chmod("{$bin}/composer", 0o700);

    $head = str_repeat('b', 40);

    return [
        'environment' => [
            'PATH' => $bin.':'.getenv('PATH'),
            'SCENARIO_TEST_COMMON_GIT' => $commonGit,
            'SCENARIO_TEST_HEAD' => $head,
        ],
        'head' => $head,
        'primary_root' => dirname($commonGit),
    ];
}

it('prints scenario usage and exits 64 for unsupported arguments', function (array $arguments) use ($wrapper) {
    $result = new Process([$wrapper, ...$arguments]);
    $result->run();

    expect($result->getExitCode())->toBe(64);
    expect($result->getErrorOutput())
        ->toContain('usage: bin/e2e-scenarios cold [CANDIDATE_SHA] [--scenario=ID ...]')
        ->toContain('bin/e2e-scenarios snapshot [CANDIDATE_SHA] [--scenario=ID ...]')
        ->toContain('bin/e2e-scenarios run [CANDIDATE_SHA] --workers=COUNT [--scenario=ID ...]')
        ->toContain('bin/e2e-scenarios cleanup RUN_ID SCENARIO_ID ATTEMPT_ID')
        ->toContain('not part of feature development');
})->with([
    'missing track' => [[]],
    'unknown track' => [['live']],
    'invalid filter form' => [['cold', '--scenario']],
    'unsafe filter' => [['cold', '--scenario=../cold']],
    'run without workers' => [['run']],
    'zero workers' => [['run', '--workers=0']],
    'negative workers' => [['run', '--workers=-1']],
    'malformed workers' => [['run', '--workers=two']],
    'duplicate workers' => [['run', '--workers=2', '--workers=3']],
    'workers on lane command' => [['cold', '--workers=2']],
]);

it('runs the cold flow with the current HEAD by default or as an explicit assertion', function (bool $explicit) use (
    $wrapper,
) {
    $fixture = scenarioWrapperFixture();
    $arguments = [$wrapper, 'cold'];
    if ($explicit) {
        $arguments[] = $fixture['head'];
    }
    $result = new Process($arguments, env: $fixture['environment']);

    expect($result->run())->toBe(0, $result->getErrorOutput());
    expect($result->getOutput())
        ->toContain("candidate={$fixture['head']}")
        ->toContain('repository='.dirname(__DIR__, 5))
        ->toContain("primary-root={$fixture['primary_root']}")
        ->toContain('arguments=--working-dir='.dirname(__DIR__, 5).'/apps/e2e scenario:cold --');
})->with([
    'resolved current HEAD' => [false],
    'explicit current HEAD' => [true],
]);

it('runs the snapshot flow with the current HEAD and its own Composer command', function () use ($wrapper) {
    $fixture = scenarioWrapperFixture();
    $result = new Process([
        $wrapper,
        'snapshot',
        '--scenario=snapshot-lifecycle',
    ], env: $fixture['environment']);

    expect($result->run())->toBe(0, $result->getErrorOutput());
    expect($result->getOutput())
        ->toContain("candidate={$fixture['head']}")
        ->toContain('repository='.dirname(__DIR__, 5))
        ->toContain("primary-root={$fixture['primary_root']}")
        ->toContain('arguments=--working-dir='.dirname(__DIR__, 5).'/apps/e2e scenario:snapshot -- --scenario=snapshot-lifecycle');
});

it('runs selected cold and snapshot flows with the requested worker count', function () use ($wrapper) {
    $fixture = scenarioWrapperFixture();
    $result = new Process([
        $wrapper,
        'run',
        '--workers=2',
        '--scenario=snapshot-lifecycle',
        '--scenario=cold-four-node',
    ], env: $fixture['environment']);

    expect($result->run())->toBe(0, $result->getErrorOutput());
    expect($result->getOutput())
        ->toContain("candidate={$fixture['head']}")
        ->toContain('repository='.dirname(__DIR__, 5))
        ->toContain("primary-root={$fixture['primary_root']}")
        ->toContain('arguments=--working-dir='.dirname(__DIR__, 5).'/apps/e2e scenario:run -- --workers=2 --scenario=snapshot-lifecycle --scenario=cold-four-node');
});

it('rejects a candidate that is not a full lowercase commit SHA', function () use ($wrapper) {
    $result = new Process([$wrapper, 'cold', 'main']);
    $result->run();

    expect($result->getExitCode())->toBe(64);
    expect($result->getErrorOutput())->toContain('40 lowercase hexadecimal characters');
});

it('rejects an explicit candidate that differs from the current HEAD', function () use ($wrapper) {
    $fixture = scenarioWrapperFixture();
    $result = new Process([$wrapper, 'cold', str_repeat('a', 40)], env: $fixture['environment']);
    $result->run();

    expect($result->getExitCode())->toBe(64);
    expect($result->getErrorOutput())->toContain('candidate must equal this checkout HEAD');
});

it('passes repeatable scenario filters in their selected order', function () use ($wrapper) {
    $fixture = scenarioWrapperFixture();
    $result = new Process([
        $wrapper,
        'cold',
        $fixture['head'],
        '--scenario=cold-construction-cleanup',
        '--scenario=cold-four-node',
    ], env: $fixture['environment']);

    expect($result->run())->toBe(0, $result->getErrorOutput());
    expect($result->getOutput())
        ->toContain('scenario:cold -- --scenario=cold-construction-cleanup --scenario=cold-four-node');
});

it('rejects a repeated scenario before invoking Composer', function () use ($wrapper) {
    $fixture = scenarioWrapperFixture();
    $result = new Process([
        $wrapper,
        'cold',
        '--scenario=cold-four-node',
        '--scenario=cold-four-node',
    ], env: $fixture['environment']);
    $result->run();

    expect($result->getExitCode())->toBe(64);
    expect($result->getErrorOutput())->toContain('selected more than once');
    expect($result->getOutput())->toBe('');
});

it('passes exact retained identities to the cleanup command without resolving a candidate', function () use ($wrapper) {
    $fixture = scenarioWrapperFixture();
    $run = str_repeat('a', 32);
    $attempt = str_repeat('b', 32);
    $result = new Process([
        $wrapper,
        'cleanup',
        $run,
        'cold-four-node',
        $attempt,
    ], env: $fixture['environment']);

    expect($result->run())->toBe(0, $result->getErrorOutput());
    expect($result->getOutput())
        ->toContain('arguments=--working-dir='.dirname(__DIR__, 5)."/apps/e2e scenario:cleanup -- {$run} cold-four-node {$attempt}");
});

it('registers the operator-invoked scenario suites outside ordinary delivery paths', function () use ($wrapper) {
    $source = (string) file_get_contents($wrapper);

    expect(is_executable($wrapper))->toBeTrue();
    expect($source)
        ->toContain('"scenario:$command"')
        ->toContain('"$command" != cold && "$command" != snapshot && "$command" != run')
        ->toContain('scenario:cleanup')
        ->toContain('ORBIT_SCENARIO_CANDIDATE_SHA')
        ->not->toContain('e2e-live', 'TOPOLOGY_SNAPSHOT_NAMESPACE', 'pcov');
});

/** @return list<ScenarioDefinition> */
function wrapperScenarioDefinitions(): array
{
    $recipe = TopologyRecipe::coldAcceptance();
    $snapshotRecipe = TopologyRecipe::registered();
    $input = ['apps/e2e/resources/guest/prepare-node.sh' => str_repeat('a', 64)];

    return [
        new ScenarioDefinition(
            new ScenarioId('first-flow'),
            'cold',
            $recipe,
            [new ScenarioAction('setup', 'construct', 60)],
            $input,
            TopologyEndState::complete($recipe),
            false,
            'first flow',
        ),
        new ScenarioDefinition(
            new ScenarioId('second-flow'),
            'cold',
            $recipe,
            [new ScenarioAction('assertion', 'verify', 60)],
            $input,
            TopologyEndState::complete($recipe),
            false,
            'second flow',
        ),
        new ScenarioDefinition(
            new ScenarioId('snapshot-flow'),
            'snapshot',
            $snapshotRecipe,
            [
                new ScenarioAction('setup', 'prepare', 60),
                new ScenarioAction('exercise', 'lifecycle', 60),
                new ScenarioAction('assertion', 'verify', 60),
            ],
            $input,
            TopologyEndState::complete($snapshotRecipe),
            false,
            'snapshot flow',
        ),
    ];
}

it('continues after a failed flow and writes the complete aggregate last', function (): void {
    $root = dirname(__DIR__, 5);
    $repository = new GitRepository($root);
    $candidate = $repository->commit();
    $paths = new StatePaths(temporaryPath('scenario-suite-', 5));
    $runs = new ScenarioRunStore(new AtomicJsonStore($paths));
    $definitions = wrapperScenarioDefinitions();
    $catalog = new ScenarioCatalog($repository, fn (): array => $definitions);
    $executed = [];
    $process = new ScenarioPestProcess(base_path(), function (
        ScenarioDefinition $definition,
        string $processCandidate,
        ScenarioRunId $run,
        AttemptId $attempt,
    ) use (&$executed, $runs): ScenarioProcessResult {
        $executed[] = $definition->id->value;
        $status = $definition->id->value === 'first-flow' ? ScenarioStatus::Failed : ScenarioStatus::Passed;
        $result = new ScenarioResult(
            $processCandidate,
            $run,
            $definition->id,
            $attempt,
            'cold',
            $status,
            $status,
            $definition->normalized(),
            $definition->fingerprint(),
            $definition->recipe->fingerprint(),
            [['name' => 'flow', 'outcome' => $status->value]],
            ['flow' => ['duration_ms' => 1]],
            null,
            [],
            [
                'removed' => [],
                'absent' => [],
                'refused' => [],
                'remaining' => [],
                'recovery_command' => "bin/e2e-scenarios cleanup {$run->value} {$definition->id->value} {$attempt->value}",
            ],
            '2026-09-10T12:00:00.000000+00:00',
            '2026-09-10T12:00:01.000000+00:00',
        );
        $runs->writeResult($result);

        return new ScenarioProcessResult($status === ScenarioStatus::Passed ? 0 : 1, "{$definition->id->value}\n");
    });
    $constructor = (new ReflectionClass(ColdTopologyConstructor::class))->newInstanceWithoutConstructor();
    $recovery = new ScenarioRecovery(
        $runs,
        $constructor,
        fn (): ColdTopologyCleanupResult => new ColdTopologyCleanupResult([], [], []),
    );
    $runner = new ScenarioSuiteRunner($catalog, $runs, $process, $recovery, new SecretRedactor);

    $aggregate = $runner->run($candidate, $root, $root, 'cold');

    expect($executed)->toBe(['first-flow', 'second-flow']);
    expect($aggregate->successful())->toBeFalse();
    expect(array_column($aggregate->toArray()['results'], 'scenario_id'))->toBe(['first-flow', 'second-flow']);
    expect($paths->path($runs->aggregatePath($aggregate->run)))->toBeFile();
});

it('recovers exact cleanup and records infrastructure-error when Pest writes no result', function (): void {
    $root = dirname(__DIR__, 5);
    $repository = new GitRepository($root);
    $candidate = $repository->commit();
    $paths = new StatePaths(temporaryPath('scenario-report-failure-', 5));
    $runs = new ScenarioRunStore(new AtomicJsonStore($paths));
    $definition = wrapperScenarioDefinitions()[0];
    $catalog = new ScenarioCatalog($repository, fn (): array => [$definition]);
    $process = new ScenarioPestProcess(
        base_path(),
        fn (): ScenarioProcessResult => new ScenarioProcessResult(70, 'injected reporting failure'),
    );
    $constructor = (new ReflectionClass(ColdTopologyConstructor::class))->newInstanceWithoutConstructor();
    $recoveries = [];
    $recovery = new ScenarioRecovery(
        $runs,
        $constructor,
        function (ScenarioRunId $run, ScenarioId $scenario, AttemptId $attempt) use (&$recoveries): ColdTopologyCleanupResult {
            $recoveries[] = [$run->value, $scenario->value, $attempt->value];

            return new ColdTopologyCleanupResult([], ['network'], [], [], "bin/e2e-scenarios cleanup {$run->value} {$scenario->value} {$attempt->value}");
        },
    );
    $runner = new ScenarioSuiteRunner($catalog, $runs, $process, $recovery, new SecretRedactor);

    $aggregate = $runner->run($candidate, $root, $root, 'cold');

    expect($recoveries)->toHaveCount(1);
    expect($aggregate->results[0]->status)->toBe(ScenarioStatus::InfrastructureError);
    expect($aggregate->results[0]->diagnostics)->toBe(['injected reporting failure']);
    expect($paths->path($runs->aggregatePath($aggregate->run)))->toBeFile();
});

it('recovers a signal-killed real process, continues, and writes the complete aggregate', function (): void {
    $root = dirname(__DIR__, 5);
    $repository = new GitRepository($root);
    $candidate = $repository->commit();
    $paths = new StatePaths(temporaryPath('scenario-interruption-', 5));
    $runs = new ScenarioRunStore(new AtomicJsonStore($paths));
    $definitions = wrapperScenarioDefinitions();
    $catalog = new ScenarioCatalog($repository, fn (): array => $definitions);
    $processRoot = temporaryPath('scenario-signal-process-', 5);
    $primary = temporaryPath('scenario-signal-primary-', 5);
    mkdir("{$processRoot}/vendor/bin", 0o700, true);
    mkdir($primary, 0o700, true);
    file_put_contents("{$processRoot}/vendor/bin/pest", <<<'PHP'
        <?php

        declare(strict_types=1);

        $scenario = getenv('ORBIT_SCENARIO_ID');
        $primary = getenv('ORBIT_SCENARIO_PRIMARY_ROOT');
        if (! is_string($scenario) || ! is_string($primary)) {
            exit(64);
        }
        file_put_contents("{$primary}/invocations.log", "{$scenario}\n", FILE_APPEND | LOCK_EX);
        echo "started {$scenario}\n";
        flush();
        if ($scenario === 'first-flow') {
            posix_kill(getmypid(), SIGKILL);
        }
        fwrite(STDERR, "second flow returned without a result\n");
        exit(70);
        PHP);
    $process = new ScenarioPestProcess($processRoot);
    $constructor = (new ReflectionClass(ColdTopologyConstructor::class))->newInstanceWithoutConstructor();
    $recoveries = [];
    $recovery = new ScenarioRecovery(
        $runs,
        $constructor,
        function (ScenarioRunId $run, ScenarioId $scenario, AttemptId $attempt) use (&$recoveries): ColdTopologyCleanupResult {
            $recoveries[] = [$run->value, $scenario->value, $attempt->value];

            return new ColdTopologyCleanupResult(
                ["removed-{$scenario->value}"],
                [],
                [],
                [],
                "bin/e2e-scenarios cleanup {$run->value} {$scenario->value} {$attempt->value}",
            );
        },
    );
    $runner = new ScenarioSuiteRunner($catalog, $runs, $process, $recovery, new SecretRedactor);

    $aggregate = $runner->run($candidate, $root, $primary, 'cold');
    $first = $aggregate->results[0];

    expect(file("{$primary}/invocations.log", FILE_IGNORE_NEW_LINES))->toBe(['first-flow', 'second-flow']);
    expect($recoveries)->toHaveCount(2);
    expect($recoveries[0])->toBe([$first->run->value, $first->scenario->value, $first->attempt->value]);
    expect($first->status)->toBe(ScenarioStatus::InfrastructureError);
    expect($first->diagnostics)->toBe(["started first-flow\nScenario process was terminated by signal 9."]);
    expect($first->cleanup)
        ->toMatchArray([
            'removed' => ['removed-first-flow'],
            'absent' => [],
            'refused' => [],
            'remaining' => [],
        ]);
    expect($first->cleanup['recovery_command'] ?? null)
        ->toBe("bin/e2e-scenarios cleanup {$first->run->value} {$first->scenario->value} {$first->attempt->value}");
    expect(array_column($aggregate->toArray()['results'], 'scenario_id'))->toBe(['first-flow', 'second-flow']);
    $aggregatePath = $paths->path($runs->aggregatePath($aggregate->run));
    expect($aggregatePath)->toBeFile();
    $persisted = json_decode((string) file_get_contents($aggregatePath), true, flags: JSON_THROW_ON_ERROR);
    expect(array_column($persisted['results'] ?? [], 'scenario_id'))->toBe(['first-flow', 'second-flow']);
});
