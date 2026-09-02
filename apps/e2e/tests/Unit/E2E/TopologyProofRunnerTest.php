<?php

declare(strict_types=1);

use App\E2E\HostCapacity;
use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\IssueState;
use App\E2E\ProofFixtureStager;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\TopologyConverger;
use App\E2E\TopologyProofRunner;
use App\E2E\TopologySnapshotManifestStore;
use App\E2E\TopologyVerifier;
use App\E2E\Value\AttemptId;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\OperationId;
use App\E2E\Value\ProofPlan;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyRequest;
use App\E2E\Value\TopologySnapshotGeneration;
use App\E2E\Value\TopologySnapshotIdentity;
use App\E2E\Value\TopologyTarget;
use App\E2E\WorktreeSynchronizer;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

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
        $repositoryRoot,
        fn () => attemptId(),
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
            )->prove($request, $plan),
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
        )->prove(new TopologyRequest('ORB-4', $fixture['worktree']), $plan))
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
