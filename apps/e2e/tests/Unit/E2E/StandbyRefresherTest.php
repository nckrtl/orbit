<?php

declare(strict_types=1);

use App\E2E\Git\GitRepository;
use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\LaravelReleaseResolver;
use App\E2E\PreparedStateFingerprint;
use App\E2E\StandbyBuilder;
use App\E2E\StandbyManifestStore;
use App\E2E\StandbyRefresher;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\OperationJournal;
use App\E2E\State\OperationLock;
use App\E2E\State\StatePaths;
use App\E2E\TopologyConverger;
use App\E2E\TopologyVerifier;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\MigrationPlan;
use App\E2E\Value\OperationId;
use App\E2E\Value\RefreshResult;
use App\E2E\WorktreeSynchronizer;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

function standbyRefresherForPowerTests(
    IncusHost $host,
    ?AtomicJsonStore $state = null,
    ?StandbyManifestStore $manifests = null,
    ?StatePaths $paths = null,
    ?string $repositoryRoot = null,
): StandbyRefresher {
    $root = $repositoryRoot ?? dirname(__DIR__, 4);
    $git = new GitRepository($root);
    $synchronizer = new WorktreeSynchronizer($host, $root);
    $converger = new TopologyConverger($host);
    $verifier = new TopologyVerifier($host, 1, 0);
    $paths ??= new StatePaths(sys_get_temp_dir().'/orbit-refresh-'.bin2hex(random_bytes(4)));
    $state ??= new AtomicJsonStore($paths);
    $manifests ??= new StandbyManifestStore($state, $paths);

    return new StandbyRefresher(
        $host,
        new IncusNetworkLifecycle($host),
        new PreparedStateFingerprint($git),
        $manifests,
        new StandbyBuilder(
            $host,
            new IncusNetworkLifecycle($host),
            $synchronizer,
            $converger,
            $verifier,
            $manifests,
            $state,
            $paths,
            $root,
        ),
        $synchronizer,
        $converger,
        $verifier,
        new LaravelReleaseResolver,
        new OperationLock($paths),
        new OperationJournal($paths),
        $state,
        $git,
        $root,
    );
}

function standbyRestoreGeneration(): \App\E2E\Value\StandbyGeneration
{
    return new \App\E2E\Value\StandbyGeneration(
        'g-'.str_repeat('a', 12),
        str_repeat('b', 40),
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        str_repeat('c', 64),
        str_repeat('d', 64),
        new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0'),
    );
}

function fakeStandbyRestoreProcesses(?int $failRestore = null, bool $failFinalProof = false): void
{
    $restores = 0;
    $realProcess = new ProcessFactory;
    Process::fake(function (PendingProcess $process) use (&$restores, $failRestore, $failFinalProof, $realProcess) {
        $command = $process->command;
        assert(is_array($command), 'Incus uses argument arrays.');
        if (($command[0] ?? null) === 'git') {
            return $realProcess->path((string) $process->path)->run($command);
        }
        if (in_array('restore', $command, true)) {
            $restores++;
            if ($restores === $failRestore) {
                throw new RuntimeException('controlled restore failure');
            }

            return Process::result();
        }
        if (in_array('image', $command, true)) {
            return Process::result(json_encode([[
                'type' => 'virtual-machine',
                'fingerprint' => str_repeat('b', 64),
                'aliases' => [['name' => 'orbit-base-ubuntu-26.04-runtime']],
            ]], JSON_THROW_ON_ERROR));
        }
        if (in_array('list', $command, true) && in_array('snapshot', $command, true)) {
            $instance = preg_replace('/\A[^:]+:/', '', $command[5] ?? '');
            $role = str_replace('orbit-e2e-standby-', '', $instance);

            return Process::result(json_encode([[
                'name' => 'main-'.$role,
                'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
            ]], JSON_THROW_ON_ERROR));
        }
        if (in_array('list', $command, true)) {
            if ($failFinalProof && $restores === 3) {
                throw new RuntimeException('controlled proof failure');
            }
            $name = preg_replace('/\A[^:]+:/', '', $command[4] ?? '');

            return Process::result(json_encode([[
                'name' => $name,
                'type' => 'virtual-machine',
                'status' => 'Stopped',
                'status_code' => 102,
                'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                'devices' => ['root' => ['pool' => 'orbit-e2e']],
            ]], JSON_THROW_ON_ERROR));
        }

        throw new RuntimeException('Unexpected Incus command: '.implode(' ', $command));
    });
}

function standbyRestoreFixture(bool $corrupt = true): array
{
    $paths = new StatePaths(sys_get_temp_dir().'/orbit-refresher-'.bin2hex(random_bytes(4)));
    $state = new AtomicJsonStore($paths);
    $manifests = new StandbyManifestStore($state, $paths);
    if ($corrupt) {
        $state->write('standby/corrupt.json', ['schema' => 1, 'message' => 'restore required']);
    }
    $manifests->promote(standbyRestoreGeneration());

    return [$paths, $state, $manifests];
}

/** @mago-expect lint:cyclomatic-complexity,halstead Test cases share one contract fixture and remain independently asserted. */
describe('StandbyRefresher contracts', function () {
    beforeEach(function () {
        $container = new Container;
        $container->instance(ProcessFactory::class, new ProcessFactory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    });

    it('returns terminal failure with evidence when standby generation is locked', function () {
        $paths = new StatePaths(sys_get_temp_dir().'/orbit-refresh-lock-'.bin2hex(random_bytes(4)));
        $state = new AtomicJsonStore($paths);
        $manifests = new StandbyManifestStore($state, $paths);
        $root = dirname(__DIR__, 4);
        $host = new IncusHost(pool: 'orbit-e2e');
        $git = new GitRepository($root);
        $synchronizer = new WorktreeSynchronizer($host, $root);
        $converger = new TopologyConverger($host);
        $verifier = new TopologyVerifier($host, 1, 0);
        $builder = new StandbyBuilder(
            $host,
            new IncusNetworkLifecycle($host),
            $synchronizer,
            $converger,
            $verifier,
            $manifests,
            $state,
            $paths,
            $root,
        );
        $requestLock = new OperationLock($paths);
        $holder = new OperationLock($paths);
        $holder->acquire('standby-generation', new OperationId(str_repeat('a', 32)), timeoutSeconds: 0);

        try {
            $refresher = new StandbyRefresher(
                $host,
                new IncusNetworkLifecycle($host),
                new PreparedStateFingerprint($git),
                $manifests,
                $builder,
                $synchronizer,
                $converger,
                $verifier,
                new LaravelReleaseResolver,
                $requestLock,
                new OperationJournal($paths),
                $state,
                $git,
                $root,
            );
            $result = $refresher->request(str_repeat('b', 40));
            $failure = $state->read('standby/failures/'.$result->evidenceId.'.json');

            expect($result->state)
                ->toBe('failed')
                ->and($failure)
                ->toMatchArray([
                    'schema' => 1,
                    'main_sha' => str_repeat('b', 40),
                    'message' => 'Unable to acquire the standby generation lock.',
                ])
                ->and($state->read('standby/request.json'))
                ->toBeNull();
        } finally {
            $holder->release();
        }
    });

    it('keeps result and external migration identities exact', function () {
        $plan = MigrationPlan::fromArray([
            'fingerprint' => str_repeat('a', 64),
            'steps' => [[
                'role' => 'gateway',
                'argv' => ['install', '-m', '0600', '/dev/stdin', '/tmp/config'],
                'stdin' => "value=yes\n",
            ]],
        ]);
        $result = new RefreshResult('promoted', str_repeat('b', 32), str_repeat('c', 32), 'generation-1');

        expect($plan->steps[0]['role'])
            ->toBe('gateway')
            ->and($plan->steps[0]['stdin'])
            ->toBe("value=yes\n")
            ->and($result->toArray()['state'])
            ->toBe('promoted')
            ->and($result->successful())
            ->toBeTrue()
            ->and(new RefreshResult('unchanged', str_repeat('b', 32), str_repeat('c', 32))->successful())
            ->toBeTrue()
            ->and(new RefreshResult('failed', str_repeat('b', 32), str_repeat('c', 32))->successful())
            ->toBeFalse();
    });

    it('confirms an unchanged generation is stopped', function () {
        $sourceRoot = dirname(__DIR__, 4);
        $cleanRoot = sys_get_temp_dir().'/orbit-refresh-worktree-'.bin2hex(random_bytes(4));
        $processes = new ProcessFactory;
        $worktree = $processes->run(['git', '-C', $sourceRoot, 'worktree', 'add', '--detach', $cleanRoot, 'HEAD']);
        expect($worktree->successful())->toBeTrue();

        try {
            $git = new GitRepository($cleanRoot);
            $mainSha = $git->commit('HEAD');
            $release = new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0');
            $prepared = new PreparedStateFingerprint($git)->forCommit($mainSha, $release);
            $paths = new StatePaths(sys_get_temp_dir().'/orbit-refresh-stopped-'.bin2hex(random_bytes(4)));
            $state = new AtomicJsonStore($paths);
            $manifests = new StandbyManifestStore($state, $paths);
            $manifests->promote(new \App\E2E\Value\StandbyGeneration(
                'stopped-test',
                $mainSha,
                ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
                $prepared->value,
                str_repeat('b', 64),
                $release,
            ));
            fakeStandbyRestoreProcesses();

            $result = standbyRefresherForPowerTests(
                new IncusHost(pool: 'orbit-e2e'),
                $state,
                $manifests,
                $paths,
                $cleanRoot,
            )->request($mainSha);

            expect($result->state)->toBe('unchanged');
            Process::assertRanTimes(
                fn (PendingProcess $process): bool => (
                    is_array($process->command)
                    && ($process->command[3] ?? null) === 'list'
                ),
                6,
            );
            Process::assertRanTimes(
                fn (PendingProcess $process): bool => (
                    is_array($process->command)
                    && ($process->command[3] ?? null) === 'snapshot'
                    && ($process->command[4] ?? null) === 'list'
                ),
                3,
            );
            Process::assertDidntRun(
                fn (PendingProcess $process): bool => (
                    is_array($process->command)
                    && (in_array('start', $process->command, true) || in_array('exec', $process->command, true))
                ),
            );
            Process::assertDidntRun(
                fn (PendingProcess $process): bool => (
                    is_array($process->command)
                    && ($process->command[1] ?? null) === 'ls-remote'
                ),
            );
        } finally {
            $processes->run(['git', '-C', $sourceRoot, 'worktree', 'remove', '--force', $cleanRoot]);
        }
    });

    it('rejects migration targets outside the fixed standby roles', function () {
        expect(fn () => new MigrationPlan(str_repeat('a', 64), [[
            'role' => 'database',
            'argv' => ['true'],
            'stdin' => '',
        ]]))
            ->toThrow(InvalidArgumentException::class);
    });

    it('clears the corrupt marker only after an exact restore succeeds', function () {
        $paths = new StatePaths(sys_get_temp_dir().'/orbit-refresher-'.bin2hex(random_bytes(4)));
        $state = new AtomicJsonStore($paths);
        $manifests = new StandbyManifestStore($state, $paths);
        $state->write('standby/corrupt.json', ['schema' => 1, 'message' => 'restore required']);
        $generation = new \App\E2E\Value\StandbyGeneration(
            'g-'.str_repeat('a', 12),
            str_repeat('b', 40),
            ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
            str_repeat('c', 64),
            str_repeat('d', 64),
            new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0'),
        );
        $manifests->promote($generation);

        Process::fake(function (PendingProcess $process) {
            $command = $process->command;
            assert(is_array($command), 'Incus uses argument arrays.');
            if (in_array('list', $command, true) && in_array('snapshot', $command, true)) {
                $instance = preg_replace('/\A[^:]+:/', '', $command[5] ?? '');
                $role = str_replace('orbit-e2e-standby-', '', $instance);

                return Process::result(json_encode([[
                    'name' => 'main-'.$role,
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                ]], JSON_THROW_ON_ERROR));
            }
            if (in_array('list', $command, true)) {
                $name = preg_replace('/\A[^:]+:/', '', $command[4] ?? '');

                return Process::result(json_encode([[
                    'name' => $name,
                    'type' => 'virtual-machine',
                    'status' => 'Stopped',
                    'status_code' => 102,
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                    'devices' => ['root' => ['pool' => 'orbit-e2e']],
                ]], JSON_THROW_ON_ERROR));
            }

            return Process::result();
        });

        $refresher = standbyRefresherForPowerTests(
            new IncusHost(pool: 'orbit-e2e'),
            $state,
            $manifests,
            $paths,
        );
        $refresher->restore();

        expect($state->read('standby/corrupt.json'))->toBeNull();
    });

    it('restores every snapshot, proves stopped state, and clears the corrupt marker', function () {
        [$paths, $state, $manifests] = standbyRestoreFixture();
        fakeStandbyRestoreProcesses();
        $refresher = standbyRefresherForPowerTests(
            new IncusHost(pool: 'orbit-e2e'),
            $state,
            $manifests,
            $paths,
        );
        expect($refresher->restore())->toEqual(standbyRestoreGeneration());
        Process::assertRanTimes(
            fn (PendingProcess $p): bool => is_array($p->command) && in_array('restore', $p->command, true),
            3,
        );
        expect($state->read('standby/corrupt.json'))->toBeNull();
    });

    it('retains the corrupt marker and restores nothing when preflight fails', function () {
        [$paths, $state, $manifests] = standbyRestoreFixture();
        Process::fake(fn (PendingProcess $p) => Process::result(
            in_array('snapshot', (array) $p->command, true)
                ? '[]'
                : json_encode([[
                    'name' => 'x',
                    'type' => 'virtual-machine',
                    'status' => 'Stopped',
                    'status_code' => 102,
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                    'devices' => ['root' => ['pool' => 'orbit-e2e']],
                ]]),
        ));
        $refresher = standbyRefresherForPowerTests(
            new IncusHost(pool: 'orbit-e2e'),
            $state,
            $manifests,
            $paths,
        );
        expect(fn () => $refresher->restore())->toThrow(RuntimeException::class);
        Process::assertDidntRun(
            fn (PendingProcess $p): bool => is_array($p->command) && in_array('restore', $p->command, true),
        );
        expect($state->read('standby/corrupt.json'))->not->toBeNull();
    });

    it('retains the corrupt marker when a restore mutation fails', function () {
        [$paths, $state, $manifests] = standbyRestoreFixture();
        fakeStandbyRestoreProcesses(2);
        $refresher = standbyRefresherForPowerTests(
            new IncusHost(pool: 'orbit-e2e'),
            $state,
            $manifests,
            $paths,
        );
        expect(fn () => $refresher->restore())->toThrow(RuntimeException::class);
        expect($state->read('standby/corrupt.json'))->not->toBeNull();
    });

    it('retains the corrupt marker when final stopped-state proof fails', function () {
        [$paths, $state, $manifests] = standbyRestoreFixture();
        fakeStandbyRestoreProcesses(null, true);
        $refresher = standbyRefresherForPowerTests(
            new IncusHost(pool: 'orbit-e2e'),
            $state,
            $manifests,
            $paths,
        );
        expect(fn () => $refresher->restore())->toThrow(RuntimeException::class);
        expect($state->read('standby/corrupt.json'))->not->toBeNull();
    });

    it('succeeds and leaves the marker absent when already absent', function () {
        [$paths, $state, $manifests] = standbyRestoreFixture(false);
        fakeStandbyRestoreProcesses();
        $refresher = standbyRefresherForPowerTests(
            new IncusHost(pool: 'orbit-e2e'),
            $state,
            $manifests,
            $paths,
        );
        $refresher->restore();
        expect($state->read('standby/corrupt.json'))->toBeNull();
    });
});
