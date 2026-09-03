<?php

declare(strict_types=1);

use App\E2E\Git\GitRepository;
use App\E2E\HostCapacity;
use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\IssueState;
use App\E2E\ObservedPhpInputCollector;
use App\E2E\ProofFixtureStager;
use App\E2E\ProofInputManifestBuilder;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\StaticProofInputPolicy;
use App\E2E\TopologyConverger;
use App\E2E\TopologyProofRunner;
use App\E2E\TopologySnapshotManifestStore;
use App\E2E\TopologyVerifier;
use App\E2E\Value\AttemptId;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\ObservedPhpInputs;
use App\E2E\Value\OperationId;
use App\E2E\Value\ProofEquivalenceReport;
use App\E2E\Value\ProofEquivalenceResult;
use App\E2E\Value\ProofInputManifest;
use App\E2E\Value\ProofPlan;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyRequest;
use App\E2E\Value\TopologySnapshotGeneration;
use App\E2E\Value\TopologySnapshotIdentity;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationReport;
use App\E2E\WorktreeSynchronizer;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

require_once __DIR__.'/Support/TopologyFixtures.php';

beforeEach(function () {
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
});

/** @return array{sourceRoot: string, worktree: string, branch: string, processes: ProcessFactory} */
function legacyProofWorktree(): array
{
    $sourceRoot = dirname(__DIR__, 5);
    $worktree = temporaryPath('orbit-legacy-proof-', 4);
    $branch = 'orb-4-legacy-proof-'.bin2hex(random_bytes(6));
    $processes = new ProcessFactory;

    expect(
        $processes->run(['git', '-C', $sourceRoot, 'worktree', 'add', '--detach', $worktree, 'HEAD'])->successful(),
    )->toBeTrue();
    expect($processes->run(['git', '-C', $worktree, 'switch', '-c', $branch])->successful())->toBeTrue();

    return compact('sourceRoot', 'worktree', 'branch', 'processes');
}

/** @param array{sourceRoot: string, worktree: string, branch: string, processes: ProcessFactory} $fixture */
function removeLegacyProofWorktree(array $fixture): void
{
    $fixture['processes']->run([
        'git',
        '-C',
        $fixture['sourceRoot'],
        'worktree',
        'remove',
        '--force',
        $fixture['worktree'],
    ]);
    $fixture['processes']->run([
        'git',
        '-C',
        $fixture['sourceRoot'],
        'branch',
        '-D',
        $fixture['branch'],
    ]);
}

function legacyProofGeneration(): TopologySnapshotGeneration
{
    return new TopologySnapshotGeneration(
        'legacy-generation',
        str_repeat('a', 40),
        [
            'gateway' => 'main-legacy-gateway',
            'app-dev' => 'main-legacy-app-dev',
            'app-prod' => 'main-legacy-app-prod',
        ],
        str_repeat('b', 64),
        str_repeat('c', 64),
        new LaravelRelease('v13.10.1', str_repeat('d', 40)),
        str_repeat('e', 64),
        1,
        'ubuntu-26.04-amd64-v1',
        'orbit-base-ubuntu-26.04-runtime',
        'gateway_app-dev_app-prod',
        TopologyProfile::ROLES,
        TopologyProfile::CHECKOUT_ROLES,
        topologyAssignments: null,
        manifestSchema: TopologySnapshotGeneration::LEGACY_SCHEMA,
    );
}

function topologyProofRunnerWithLegacyGeneration(
    string $repositoryRoot,
    StatePaths $paths,
    TopologySnapshotManifestStore $manifests,
): TopologyProofRunner {
    $host = new IncusHost(pool: 'orbit-e2e');
    $operation = new OperationId(str_repeat('f', 32));

    return new TopologyProofRunner(
        $host,
        new IncusNetworkLifecycle($host),
        $manifests,
        new WorktreeSynchronizer($host, $repositoryRoot, $operation),
        new TopologyConverger($host),
        new TopologyVerifier($host, 1, 0),
        new ProofFixtureStager($host, $operation),
        new HostCapacity($host, 24),
        $paths,
        $operation,
        TopologySnapshotIdentity::primary(),
        new ProofInputManifestBuilder(new StaticProofInputPolicy),
        new ObservedPhpInputCollector($host),
        $repositoryRoot,
        fn () => attemptId(),
    );
}

/** @return array{root:string,worktree:string,paths:StatePaths,manifests:TopologySnapshotManifestStore,request:TopologyRequest,target:TopologyTarget,candidate:string,proved:string,report:ProofEquivalenceReport,operation:OperationId} */
function candidateConvergenceFixture(): array
{
    $root = preparedTopologyRepository();
    $worktree = pinnedFeatureWorktree($root, 'candidate-convergence');
    $repository = new GitRepository($worktree);
    $candidate = $repository->commit();
    $proved = $repository->commit('HEAD^');
    $paths = new StatePaths(temporaryPath('orbit-candidate-convergence-state-', 4));
    promoteDiscoveryGeneration($root, $paths);
    $host = new IncusHost(pool: 'default');
    $manifests = new TopologySnapshotManifestStore(new AtomicJsonStore($paths), $paths, $host);
    $generation = $manifests->promoted();
    assert($generation !== null);
    $target = TopologyTarget::feature('NCK-123', new AttemptId(str_repeat('a', 32)));
    $operation = new OperationId(str_repeat('b', 32));
    $state = IssueState::forWorktree('NCK-123', $worktree);
    $state->writeAttempt($target->requireAttempt(), AttemptPurpose::Proof, $operation);
    $verification = new VerificationReport(true, [
        'proof.verify' => [
            'passed' => true,
            'checked_at' => '2026-09-03T00:00:00Z',
            'expected' => 'healthy',
            'observed' => 'healthy',
            'evidence_ref' => 'incus://'.$target->instance('gateway').'/proof.verify',
        ],
    ]);
    $state->writeTopology(new FeatureTopology(
        $target,
        AttemptPurpose::Proof,
        $generation,
        $target->network(),
        array_combine(TopologyProfile::ROLES, array_map($target->instance(...), TopologyProfile::ROLES)),
        new SourceState($proved, $proved, operationId: $operation->value),
        $verification,
    ));
    $packages = array_fill_keys(ObservedPhpInputs::PACKAGES, '8.5.10-sury');
    $packages['php8.5-pcov'] = '1.0.12-sury';
    $runtime = static fn (string $role): array => [
        'role' => $role,
        'php_version' => '8.5.10',
        'fpm_version' => '8.5.10',
        'pcov_version' => '1.0.12',
        'package_versions' => $packages,
    ];
    $surface = static fn (string $role, string $type, string $id): array => [
        'role' => $role,
        'process_type' => $type,
        'processes' => [[
            'id' => str_repeat($id, 32),
            'started_at' => '2026-09-03T00:00:00.000001Z',
            'finished_at' => '2026-09-03T00:00:00.000002Z',
        ]],
        'paths' => ['apps/cli/orbit'],
    ];
    $surfaces = [
        $surface('app-dev', 'cli', '1'),
        $surface('gateway', 'cli', '2'),
        $surface('gateway', 'fpm', '3'),
    ];
    $observed = new ObservedPhpInputs(
        [$runtime('app-dev'), $runtime('gateway')],
        ['setup' => $surfaces, 'acceptance' => $surfaces],
    );
    $manifest = new ProofInputManifest(
        StaticProofInputPolicy::VERSION,
        $proved,
        $proved,
        [],
        [[
            'path' => 'proofs/NCK-123.json',
            'classification' => 'proof-contract',
            'mode' => '100644',
            'blob' => str_repeat('d', 40),
        ]],
        'proofs/NCK-123.json',
        [],
        [],
        $observed,
        [
            'static_classification' => true,
            'proof_contract' => true,
            'checkout_literals' => true,
            'observed_processes' => true,
            'observed_paths' => true,
            'pcov_cleanup' => true,
        ],
    );
    $planFingerprint = str_repeat('e', 64);
    $state->writeProofInputManifest($manifest->fingerprint(), $manifest->toArray());
    $state->writeProof([
        'status' => 'proved',
        'issue' => 'NCK-123',
        'attempt_id' => $target->requireAttempt()->value,
        'candidate_sha' => $proved,
        'plan_sha256' => $planFingerprint,
        'manifest_sha256' => $manifest->fingerprint(),
        'actions' => [],
        'recorded_at' => '2026-09-03T00:00:00Z',
    ]);
    $report = new ProofEquivalenceReport(
        $proved,
        $candidate,
        $proved,
        $planFingerprint,
        $manifest->fingerprint(),
        ProofEquivalenceResult::Equivalent,
        [[
            'path' => 'apps/cli/app/Unrelated.php',
            'previous_path' => null,
            'change' => 'content-changed',
            'classification' => 'unrelated-runtime',
        ]],
        'candidate-convergence',
        'run-candidate-convergence',
        [],
        '2026-09-03T00:00:00Z',
    );
    $state->writeEquivalence($report->fingerprint(), $report->toArray());

    return (
        compact(
            'root',
            'worktree',
            'paths',
            'manifests',
            'target',
            'candidate',
            'proved',
            'report',
            'operation',
        ) + ['request' => new TopologyRequest('NCK-123', $worktree)]
    );
}

it('converges and verifies an authorized exact candidate without rerunning acceptance actions', function (): void {
    $fixture = candidateConvergenceFixture();
    $attempt = new AttemptId(str_repeat('c', 32));
    $candidateTarget = TopologyTarget::feature('NCK-123', $attempt);
    $candidateTree = new GitRepository($fixture['worktree'])->tree($fixture['candidate']);
    $events = [];
    $packageVersions = array_fill_keys([
        'php8.5-cli',
        'php8.5-fpm',
        'php8.5-common',
        'php8.5-curl',
        'php8.5-mbstring',
        'php8.5-sqlite3',
        'php8.5-xml',
    ], '8.5.10-sury');
    $runtime = json_encode([
        'php_version' => '8.5.10',
        'fpm_version' => '8.5.10',
        'pcov_version' => null,
        'package_versions' => $packageVersions,
    ], JSON_THROW_ON_ERROR);
    fakePinnedWorktreeProcesses(
        $candidateTarget,
        $events,
        guestOverride: static function (array $guest) use ($candidateTree, $fixture, $runtime) {
            if ($guest === ['/usr/local/bin/observe-php.sh', 'runtime-info', 'runtime']) {
                return Process::result($runtime);
            }
            if ($guest === ['git', '-C', '/home/orbit/orbit', 'rev-parse', '--verify', 'HEAD^{commit}']) {
                return Process::result($fixture['candidate']."\n");
            }
            if ($guest === ['git', '-C', '/home/orbit/orbit', 'rev-parse', '--verify', 'HEAD^{tree}']) {
                return Process::result($candidateTree."\n");
            }
            if (
                $guest === [
                    'git',
                    '-C',
                    '/home/orbit/orbit',
                    'status',
                    '--porcelain=v1',
                    '--untracked-files=all',
                ]
            ) {
                return Process::result();
            }

            return null;
        },
        operationId: $fixture['operation']->value,
    );

    $result = candidateConvergenceRunner($fixture, $attempt)->convergeCandidate($fixture['request']);

    $state = IssueState::forWorktree('NCK-123', $fixture['worktree']);
    $recorded = $state->candidateConvergence();
    $commands = array_map(
        static fn (array $event): string => implode(' ', array_map(strval(...), $event)),
        $events,
    );
    expect($result['error'])
        ->toBeNull()
        ->and($result)
        ->toMatchArray([
            'status' => 'converged',
            'issue' => 'NCK-123',
            'attempt_id' => $attempt->value,
            'candidate_sha' => $fixture['candidate'],
            'equivalence_sha256' => $fixture['report']->fingerprint(),
            'error' => null,
        ])
        ->and($result['convergence']['converged'])
        ->toBeTrue()
        ->and($result['verification']['passed'])
        ->toBeTrue()
        ->and($recorded)
        ->toBe($result)
        ->and($state->isProved())
        ->toBeTrue()
        ->and($state->requireTopology(AttemptPurpose::CandidateConvergence)->source->hostSha)
        ->toBe($fixture['candidate'])
        ->and(implode("\n", $commands))
        ->toContain(
            '/usr/local/bin/receive-source.sh',
            '/usr/local/bin/observe-php.sh prepare runtime',
            '/usr/local/bin/converge-gateway.sh',
            '/usr/local/bin/verify-topology.sh',
        )
        ->not->toContain('/var/lib/orbit-e2e/proof');
});

function candidateConvergenceRunner(
    array $fixture,
    AttemptId $attempt,
): TopologyProofRunner {
    $host = new IncusHost(pool: 'default');

    return new TopologyProofRunner(
        $host,
        new IncusNetworkLifecycle($host),
        $fixture['manifests'],
        new WorktreeSynchronizer($host, $fixture['root'], $fixture['operation']),
        new TopologyConverger($host),
        new TopologyVerifier($host, 1, 0),
        new ProofFixtureStager($host, $fixture['operation']),
        new HostCapacity($host, 24),
        $fixture['paths'],
        $fixture['operation'],
        TopologySnapshotIdentity::primary(),
        new ProofInputManifestBuilder(new StaticProofInputPolicy),
        new ObservedPhpInputCollector($host),
        $fixture['root'],
        static fn (): AttemptId => $attempt,
    );
}

/** @return array{id:string,node:string,exit_code:int,stdout:string,stderr:string} */
function runProofAction(
    int $exitCode,
    int &$transportTimeout,
    array &$transportArgv,
): array {
    $attempt = new AttemptId(str_repeat('a', 32));
    $target = TopologyTarget::feature('ORB-7', $attempt);
    $instance = $target->instance('app-dev');
    Process::fake(function (PendingProcess $process) use ($exitCode, $instance, &$transportTimeout, &$transportArgv) {
        if (($process->command[3] ?? null) === 'list') {
            return Process::result(json_encode([[
                'name' => $instance,
                'type' => 'virtual-machine',
                'status' => 'Running',
                'status_code' => 103,
                'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                'devices' => ['root' => ['pool' => 'orbit-e2e']],
            ]], JSON_THROW_ON_ERROR));
        }

        $transportTimeout = $process->timeout;
        $transportArgv = array_slice($process->command, 6);

        return Process::result('action output', 'action error', $exitCode);
    });
    $runner = topologyProofRunnerWithLegacyGeneration(
        dirname(__DIR__, 5),
        new StatePaths(temporaryPath('orbit-proof-action-state-', 4)),
        new TopologySnapshotManifestStore(
            new AtomicJsonStore(new StatePaths(temporaryPath('orbit-proof-action-manifest-', 4))),
            new StatePaths(temporaryPath('orbit-proof-action-paths-', 4)),
            new IncusHost(pool: 'orbit-e2e'),
        ),
    );
    $actions = [];
    $method = new ReflectionMethod(TopologyProofRunner::class, 'runActions');

    $method->invokeArgs($runner, [
        $target,
        'acceptance',
        [[
            'id' => 'proof-action',
            'node' => 'app-dev',
            'argv' => ['bash', '/var/lib/orbit-e2e/proof/action.sh'],
            'timeout_seconds' => 30,
        ]],
        &$actions,
    ]);

    return $actions[0];
}

it('refuses proof from a schema 4 generation before creating an attempt', function () {
    $fixture = legacyProofWorktree();

    try {
        $paths = new StatePaths(temporaryPath('orbit-legacy-proof-state-', 4));
        $state = new AtomicJsonStore($paths);
        $manifests = new TopologySnapshotManifestStore($state, $paths, new IncusHost(pool: 'orbit-e2e'));
        $manifests->promote(legacyProofGeneration());
        $request = new TopologyRequest('ORB-4', $fixture['worktree']);
        $plan = ProofPlan::fromArray([
            'setup' => [],
            'acceptance' => [[
                'id' => 'legacy-refusal',
                'node' => 'gateway',
                'argv' => ['true'],
                'timeout_seconds' => 30,
            ]],
        ]);

        expect(
            fn () => topologyProofRunnerWithLegacyGeneration(
                $fixture['sourceRoot'],
                $paths,
                $manifests,
            )->prove($request, $plan, 'proofs/NCK-103.json'),
        )
            ->toThrow(RuntimeException::class, 'legacy; refresh it before proof')
            ->and(IssueState::forWorktree('ORB-4', $fixture['worktree'])->hasAttempt())
            ->toBeFalse();
    } finally {
        removeLegacyProofWorktree($fixture);
    }
});

it('allows proof preparation while discovery remains active', function () {
    $fixture = legacyProofWorktree();

    try {
        $paths = new StatePaths(temporaryPath('orbit-legacy-proof-state-', 4));
        $manifests = new TopologySnapshotManifestStore(
            new AtomicJsonStore($paths),
            $paths,
            new IncusHost(pool: 'orbit-e2e'),
        );
        $manifests->promote(legacyProofGeneration());
        $state = IssueState::forWorktree('ORB-4', $fixture['worktree']);
        $discovery = new AttemptId(str_repeat('c', 32));
        $state->writeAttempt($discovery, AttemptPurpose::Discovery, new OperationId(str_repeat('d', 32)));
        $plan = ProofPlan::fromArray([
            'setup' => [],
            'acceptance' => [[
                'id' => 'legacy-refusal',
                'node' => 'gateway',
                'argv' => ['true'],
                'timeout_seconds' => 30,
            ]],
        ]);

        expect(fn () => topologyProofRunnerWithLegacyGeneration(
            $fixture['sourceRoot'],
            $paths,
            $manifests,
        )->prove(new TopologyRequest('ORB-4', $fixture['worktree']), $plan, 'proofs/NCK-103.json'))
            ->toThrow(RuntimeException::class, 'legacy; refresh it before proof')
            ->and($state->attemptId(AttemptPurpose::Discovery)->value)
            ->toBe($discovery->value)
            ->and($state->hasAttempt(AttemptPurpose::Proof))
            ->toBeFalse();
    } finally {
        removeLegacyProofWorktree($fixture);
    }
});

it('gives proof actions a catchable deadline and bounded transport headroom', function () {
    $transportTimeout = 0;
    $transportArgv = [];

    runProofAction(0, $transportTimeout, $transportArgv);

    expect($transportArgv)
        ->toBe([
            ...GuestCommand::ORBIT_USER_PREFIX,
            'timeout',
            '--signal=TERM',
            '--kill-after=5s',
            '30s',
            'bash',
            '/var/lib/orbit-e2e/proof/action.sh',
        ])
        ->and($transportTimeout)
        ->toBe(37);
});

it('fails a proof action that exits after its term deadline', function () {
    $transportTimeout = 0;
    $transportArgv = [];

    expect(fn () => runProofAction(124, $transportTimeout, $transportArgv))
        ->toThrow(RuntimeException::class, 'Proof acceptance action [proof-action] failed with exit code 124.');
});

it('fails a proof action force-killed after its cleanup grace', function () {
    $transportTimeout = 0;
    $transportArgv = [];

    expect(fn () => runProofAction(137, $transportTimeout, $transportArgv))
        ->toThrow(RuntimeException::class, 'Proof acceptance action [proof-action] failed with exit code 137.');
});

it('keeps ordinary orbit-user commands unchanged', function () {
    $command = GuestCommand::asOrbitUser(['orbit', 'node:list', '--json'], 30);

    expect($command->command)
        ->toBe([...GuestCommand::ORBIT_USER_PREFIX, 'orbit', 'node:list', '--json'])
        ->and($command->timeout)
        ->toBe(30);
});
