<?php

declare(strict_types=1);

use App\E2E\Git\GitRepository;
use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\LaravelReleaseResolver;
use App\E2E\PreparedStateFingerprint;
use App\E2E\StandbyAvailability;
use App\E2E\StandbyBuilder;
use App\E2E\StandbyManifestStore;
use App\E2E\StandbyRefresher;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\OperationLock;
use App\E2E\State\StatePaths;
use App\E2E\TopologyConverger;
use App\E2E\TopologyVerifier;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\OperationId;
use App\E2E\Value\RefreshResult;
use App\E2E\Value\StandbyGeneration;
use App\E2E\Value\StandbyIdentity;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyTarget;
use App\E2E\WorktreeSynchronizer;
use Illuminate\Container\Container;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

/** @mago-expect lint:excessive-parameter-list Explicit fixture dependencies keep this test boundary configurable. */
function standbyRefresherForPowerTests(
    IncusHost $host,
    ?AtomicJsonStore $state = null,
    ?StandbyManifestStore $manifests = null,
    ?StatePaths $paths = null,
    ?string $repositoryRoot = null,
    ?OperationId $operation = null,
    int $refreshLockTimeoutSeconds = 3600,
): StandbyRefresher {
    $root = $repositoryRoot ?? dirname(__DIR__, 4);
    $operation ??= new OperationId(str_repeat('a', 32));
    $git = new GitRepository($root);
    $synchronizer = new WorktreeSynchronizer($host, $root, $operation);
    $converger = new TopologyConverger($host);
    $verifier = new TopologyVerifier($host, 1, 10_000);
    $paths ??= new StatePaths(temporaryPath('orbit-refresh-', 4));
    $state ??= new AtomicJsonStore($paths);
    $manifests ??= new StandbyManifestStore($state, $paths, new IncusHost);

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
            $root,
            StandbyIdentity::primary(),
        ),
        $synchronizer,
        $converger,
        $verifier,
        new LaravelReleaseResolver,
        new OperationLock($paths),
        new OperationLock($paths),
        $state,
        $git,
        $root,
        $operation,
        StandbyIdentity::primary(),
        new StandbyAvailability($host, StandbyIdentity::primary()),
        $refreshLockTimeoutSeconds,
    );
}

it('uses the injected operation ID for refresh results', function () {
    $operation = new OperationId(str_repeat('e', 32));
    $result = standbyRefresherForPowerTests(new IncusHost, operation: $operation)
        ->request(str_repeat('b', 40));

    expect($result->operationId)->toBe($operation->value);
});

it('waits for the generation mutation lock for the shared pin window', function () {
    $reflection = new ReflectionClass(StandbyRefresher::class);

    expect($reflection->getConstant('GENERATION_MUTATION_LOCK_TIMEOUT_SECONDS'))->toBe(3600);
});

function standbyRestoreGeneration(): \App\E2E\Value\StandbyGeneration
{
    return new \App\E2E\Value\StandbyGeneration(
        'g-'.str_repeat('a', 12),
        str_repeat('b', 40),
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        str_repeat('c', 64),
        str_repeat('d', 64),
        new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0'),
        str_repeat('e', 64),
        2,
        'ubuntu-26.04-amd64-v1',
        'orbit-base-ubuntu-26.04-runtime',
        'gateway_app-dev_app-prod',
        ['gateway', 'app-dev', 'app-prod'],
        ['gateway', 'app-dev'],
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
            return $realProcess
                ->path((string) $process->path)
                ->input($process->input)
                ->run($command);
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
                'created_at' => '2026-01-01T00:00:00Z',
                'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
            ]], JSON_THROW_ON_ERROR));
        }
        if (in_array('list', $command, true)) {
            if ($failFinalProof && $restores === 3) {
                throw new RuntimeException('controlled proof failure');
            }
            $name = preg_replace('/\A[^:]+:/', '', $command[4] ?? '');
            $names = $name === ''
                ? array_map(TopologyTarget::standby()->instance(...), TopologyProfile::ROLES)
                : [$name];

            return Process::result(json_encode(array_map(static function (string $instance): array {
                $role = str_replace('orbit-e2e-standby-', '', $instance);
                $address = match ($role) {
                    'gateway' => '10',
                    'app-dev' => '11',
                    'app-prod' => '12',
                    default => throw new RuntimeException('Unknown standby role.'),
                };

                return [
                    'name' => $instance,
                    'type' => 'virtual-machine',
                    'status' => 'Stopped',
                    'status_code' => 102,
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                    'devices' => [
                        'root' => ['pool' => 'orbit-e2e'],
                        'eth0' => [
                            'network' => 'oe-standby',
                            'hwaddr' => '00:16:3e:77:ee:5a',
                            'ipv4.address' => '10.232.1.'.$address,
                        ],
                    ],
                ];
            }, $names), JSON_THROW_ON_ERROR));
        }

        throw new RuntimeException('Unexpected Incus command: '.implode(' ', $command));
    });
}

function standbyRestoreFixture(bool $corrupt = true): array
{
    $paths = new StatePaths(temporaryPath('orbit-refresher-', 4));
    $state = new AtomicJsonStore($paths);
    $manifests = new StandbyManifestStore($state, $paths, new IncusHost);
    if ($corrupt) {
        $state->write('standby/corrupt.json', ['schema' => 1, 'message' => 'restore required']);
    }
    $manifests->promote(standbyRestoreGeneration());

    return [$paths, $state, $manifests];
}

/** @return object{existingSnapshots: array<string, string>, createAttempts: int, gatewayDeleteFailed: bool, deleted: list<string>} */
function candidateSnapshotProcessState(): object
{
    return (object) [
        'existingSnapshots' => [],
        'createAttempts' => 0,
        'gatewayDeleteFailed' => false,
        'deleted' => [],
    ];
}

/** @param object{existingSnapshots: array<string, string>, createAttempts: int, gatewayDeleteFailed: bool, deleted: list<string>} $state */
function candidateSnapshotProcess(PendingProcess $process, object $state): ProcessResult
{
    $command = $process->command;
    assert(is_array($command), 'Incus uses argument arrays.');

    if (($command[3] ?? null) === 'list') {
        return candidateSnapshotVm($command);
    }

    return match ($command[4] ?? null) {
        'list' => candidateSnapshotList($command, $state),
        'create' => candidateSnapshotCreate($command, $state),
        'delete' => candidateSnapshotDelete($command, $state),
        default => throw new RuntimeException('Unexpected Incus command: '.implode(' ', $command)),
    };
}

/** @param array<int, string> $command */
function candidateSnapshotVm(array $command): ProcessResult
{
    $name = preg_replace('/\A[^:]+:/', '', $command[4] ?? '');
    $names = $name === ''
        ? array_map(TopologyTarget::standby()->instance(...), TopologyProfile::ROLES)
        : [$name];

    return Process::result(json_encode(array_map(static fn (string $instance): array => [
        'name' => $instance,
        'type' => 'virtual-machine',
        'status' => 'Stopped',
        'status_code' => 102,
        'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
        'devices' => ['root' => ['pool' => 'orbit-e2e']],
    ], $names), JSON_THROW_ON_ERROR));
}

/**
 * @param array<int, string> $command
 * @param object{existingSnapshots: array<string, string>, createAttempts: int, gatewayDeleteFailed: bool, deleted: list<string>} $state
 */
function candidateSnapshotList(array $command, object $state): ProcessResult
{
    $instance = preg_replace('/\A[^:]+:/', '', $command[5] ?? '');
    $snapshot = $state->existingSnapshots[$instance] ?? null;

    return Process::result(
        $snapshot === null
            ? '[]'
            : json_encode([[
                'name' => $snapshot,
                'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
            ]], JSON_THROW_ON_ERROR),
    );
}

/**
 * @param array<int, string> $command
 * @param object{existingSnapshots: array<string, string>, createAttempts: int, gatewayDeleteFailed: bool, deleted: list<string>} $state
 */
function candidateSnapshotCreate(array $command, object $state): ProcessResult
{
    $instance = preg_replace('/\A[^:]+:/', '', $command[5] ?? '');
    $state->createAttempts++;
    if ($state->createAttempts === 3) {
        return Process::result(errorOutput: 'candidate create failed', exitCode: 1);
    }
    $state->existingSnapshots[$instance] = $command[6];

    return Process::result();
}

/**
 * @param array<int, string> $command
 * @param object{existingSnapshots: array<string, string>, createAttempts: int, gatewayDeleteFailed: bool, deleted: list<string>} $state
 */
function candidateSnapshotDelete(array $command, object $state): ProcessResult
{
    $instance = preg_replace('/\A[^:]+:/', '', $command[5] ?? '');
    $state->deleted[] = $instance;
    if ($instance === 'orbit-e2e-standby-gateway' && ! $state->gatewayDeleteFailed) {
        $state->gatewayDeleteFailed = true;

        return Process::result(errorOutput: 'controlled cleanup failure', exitCode: 1);
    }
    unset($state->existingSnapshots[$instance]);

    return Process::result();
}

/** @return object{events: list<string>, running: array<string, bool>, snapshots: array<string, string>, staleSnapshots: array<string, string>, pruneLockResults: list<bool>, failReadiness: bool, paths: StatePaths} */
function refreshProcessState(StatePaths $paths, bool $failReadiness = false): object
{
    $staleSnapshots = [];
    foreach (TopologyProfile::ROLES as $role) {
        $staleSnapshots[TopologyTarget::standby()->instance($role)] = 'main-stale-'.$role;
    }

    return (object) [
        'events' => [],
        'running' => [],
        'snapshots' => [],
        'staleSnapshots' => $staleSnapshots,
        'pruneLockResults' => [],
        'failReadiness' => $failReadiness,
        'paths' => $paths,
    ];
}

/**
 * @param object{events: list<string>, running: array<string, bool>, snapshots: array<string, string>, staleSnapshots: array<string, string>, pruneLockResults: list<bool>, failReadiness: bool, paths: StatePaths} $state
 * @mago-expect lint:cyclomatic-complexity,halstead,kan-defect The fake maps one complete refresh process boundary.
 */
function refreshProcess(
    PendingProcess $process,
    object $state,
    ProcessFactory $realProcess,
    string $oldSha,
): ProcessResult {
    $command = $process->command;
    assert(is_array($command), 'Processes use argument arrays.');

    if (
        ($command[0] ?? null) === 'python3'
        && str_ends_with((string) ($command[1] ?? ''), '/resources/host/exec-all.py')
    ) {
        $payload = json_decode((string) $process->input, true, 512, JSON_THROW_ON_ERROR);
        $results = [];
        foreach ($payload['requests'] as $request) {
            $result = refreshGuestProcess($request['argv'], $request['instance'], $state, $oldSha);
            $results[] = [
                'label' => $request['label'],
                'stdout' => $result->output(),
                'stderr' => $result->errorOutput(),
                'exit_code' => $result->exitCode(),
            ];
        }

        return Process::result(json_encode($results, JSON_THROW_ON_ERROR));
    }

    if (($command[0] ?? null) === 'git' && ($command[1] ?? null) !== 'ls-remote') {
        return $realProcess
            ->path((string) $process->path)
            ->env($process->environment)
            ->input($process->input)
            ->run($command);
    }

    if (($command[1] ?? null) === 'ls-remote') {
        return Process::result(str_repeat('e', 40)."\trefs/tags/v13.10.1\n");
    }

    if (
        ($command[0] ?? null) === 'python3'
        && str_ends_with((string) ($command[1] ?? ''), '/resources/host/reconcile-firewall.py')
    ) {
        return Process::result(json_encode(['changed' => false], JSON_THROW_ON_ERROR));
    }

    if (($command[0] ?? null) === 'sudo') {
        return Process::result(exitCode: in_array('-C', $command, true) ? 1 : 0);
    }

    if (($command[3] ?? null) === 'image') {
        return Process::result(json_encode([[
            'type' => 'virtual-machine',
            'fingerprint' => str_repeat('d', 64),
            'aliases' => [['name' => 'orbit-base-ubuntu-26.04-runtime']],
        ]], JSON_THROW_ON_ERROR));
    }

    if (($command[3] ?? null) === 'network' && ($command[4] ?? null) === 'list') {
        return Process::result(json_encode([[
            'name' => 'oe-standby',
            'config' => [
                'user.orbit.e2e.owner' => 'orbit-e2e',
                'ipv4.address' => '10.232.1.1/24',
                'ipv4.nat' => 'true',
                'ipv6.address' => 'none',
                'raw.dnsmasq' => 'port=0',
            ],
        ]], JSON_THROW_ON_ERROR));
    }

    if (($command[3] ?? null) === 'snapshot' && ($command[4] ?? null) === 'list') {
        $instance = preg_replace('/\A[^:]+:/', '', $command[5] ?? '');
        $role = str_replace('orbit-e2e-standby-', '', $instance);

        $snapshots = [[
            'name' => $state->snapshots[$instance] ?? 'main-old-'.$role,
            'created_at' => '2026-01-01T00:00:00Z',
            'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
        ]];
        $snapshots[] = [
            'name' => 'main-rollback-'.$role,
            'created_at' => '2025-12-31T00:00:00Z',
            'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
        ];
        if (isset($state->staleSnapshots[$instance])) {
            $snapshots[] = [
                'name' => $state->staleSnapshots[$instance],
                'created_at' => '2026-01-02T00:00:00Z',
                'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
            ];
        }

        return Process::result(json_encode($snapshots, JSON_THROW_ON_ERROR));
    }

    if (($command[3] ?? null) === 'list') {
        $name = preg_replace('/\A[^:]+:/', '', $command[4] ?? '');
        $names = $name === ''
            ? array_map(TopologyTarget::standby()->instance(...), TopologyProfile::ROLES)
            : [$name];

        return Process::result(json_encode(array_map(static function (string $instance) use ($state): array {
            $running = $state->running[$instance] ?? false;
            $role = str_replace('orbit-e2e-standby-', '', $instance);
            $mac = match ($role) {
                'gateway' => '00:16:3e:77:ee:5a',
                'app-dev' => '00:16:3e:71:18:e5',
                'app-prod' => '00:16:3e:a3:2d:6c',
                default => throw new RuntimeException('Unknown standby role.'),
            };

            return [
                'name' => $instance,
                'type' => 'virtual-machine',
                'status' => $running ? 'Running' : 'Stopped',
                'status_code' => $running ? 103 : 102,
                'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                'devices' => [
                    'root' => ['pool' => 'orbit-e2e'],
                    'eth0' => [
                        'network' => 'oe-standby',
                        'hwaddr' => $mac,
                        'ipv4.address' => '10.232.1.'.match ($role) {
                            'gateway' => '10',
                            'app-dev' => '11',
                            'app-prod' => '12',
                        },
                    ],
                ],
            ];
        }, $names), JSON_THROW_ON_ERROR));
    }

    if (($command[3] ?? null) === 'start') {
        $target = $command[4];
        $name = preg_replace('/\A[^:]+:/', '', $target);
        $state->events[] = "start:{$target}";
        $state->running[$name] = true;

        return Process::result();
    }

    if (($command[3] ?? null) === 'stop') {
        $name = preg_replace('/\A[^:]+:/', '', $command[4] ?? '');
        $state->running[$name] = false;

        return Process::result();
    }

    if (($command[3] ?? null) === 'snapshot' && ($command[4] ?? null) === 'create') {
        $instance = preg_replace('/\A[^:]+:/', '', $command[5] ?? '');
        $state->snapshots[$instance] = $command[6];
        if (! in_array('snapshot', $state->events, true)) {
            $state->events[] = 'snapshot';
        }

        return Process::result();
    }

    if (($command[3] ?? null) === 'snapshot' && ($command[4] ?? null) === 'restore') {
        $state->events[] = 'restore:'.preg_replace('/\A[^:]+:/', '', $command[5] ?? '').'/'.($command[6] ?? '');

        return Process::result();
    }

    if (($command[3] ?? null) === 'snapshot' && ($command[4] ?? null) === 'delete') {
        $instance = preg_replace('/\A[^:]+:/', '', $command[5] ?? '');
        $snapshot = $command[6] ?? null;
        if (
            ($state->staleSnapshots[$instance] ?? null) === $snapshot
            || str_starts_with((string) $snapshot, 'main-rollback-')
        ) {
            $probe = new OperationLock($state->paths);
            $acquired = $probe->acquire(
                'standby-generation',
                new OperationId(str_repeat('f', 32)),
                timeoutSeconds: 0,
            );
            $state->pruneLockResults[] = $acquired;
            if ($acquired) {
                $probe->release();
            }
            unset($state->staleSnapshots[$instance]);
        }

        return Process::result();
    }

    if (($command[3] ?? null) !== 'exec') {
        return Process::result();
    }

    return refreshGuestProcess(array_slice($command, 6), $command[4], $state, $oldSha);
}

/**
 * @param list<string> $guestArguments
 * @param object{events: list<string>, running: array<string, bool>, snapshots: array<string, string>, staleSnapshots: array<string, string>, pruneLockResults: list<bool>, failReadiness: bool, paths: StatePaths} $state
 */
/** @mago-expect lint:cyclomatic-complexity The fake models the complete guest refresh protocol at one test boundary. */
function refreshGuestProcess(array $guestArguments, string $target, object $state, string $oldSha): ProcessResult
{
    if ($guestArguments === ['/bin/true']) {
        $state->events[] = "agent:{$target}";

        return Process::result();
    }

    if (
        $guestArguments === [
            'sh',
            '-c',
            'interface=$(ip -4 route show default | awk \'$1 == "default" { for (i = 2; i < NF; i++) if ($i == "dev") { print $(i + 1); exit } }\') && [ -n "$interface" ] && ip -4 -o addr show dev "$interface" scope global',
        ]
    ) {
        $event = "ipv4:{$target}";
        if (! in_array($event, $state->events, true)) {
            $state->events[] = $event;
        }

        return Process::result("2: enp5s0 inet 192.0.2.10/24 scope global enp5s0\n");
    }

    if (in_array('git', $guestArguments, true) && in_array('rev-parse', $guestArguments, true)) {
        return Process::result($oldSha."\n");
    }

    if (
        ($guestArguments[0] ?? null) === 'runuser'
        && in_array('/usr/local/bin/receive-source.sh', $guestArguments, true)
    ) {
        $shas = array_values(array_filter(
            $guestArguments,
            fn (string $argument): bool => preg_match('/\A[a-f0-9]{40}\z/D', $argument) === 1,
        ));

        return Process::result(json_encode([
            'sha' => $shas[0],
            'tree_hash' => $guestArguments[array_key_last($guestArguments)],
        ], JSON_THROW_ON_ERROR));
    }

    if (in_array('ssh-keygen', $guestArguments, true)) {
        return Process::result('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOJgN5jVtcfw7oASD2F6If4O5mQ/HZBqbrw4QC9PcHEO');
    }

    if (in_array('uname', $guestArguments, true)) {
        return Process::result("x86_64\n");
    }

    if (
        $guestArguments === ['/usr/local/bin/prepare-node.sh', 'permissions']
        && str_ends_with($target, '-app-prod')
    ) {
        $state->events[] = 'convergence';
    }

    if (($guestArguments[0] ?? null) === '/usr/local/bin/verify-topology.sh') {
        $mode = $guestArguments[2];
        if (! in_array($mode, $state->events, true)) {
            $state->events[] = $mode;
        }

        return Process::result(json_encode([
            'probe' => $guestArguments[1],
            'passed' => ! ($mode === 'readiness' && $state->failReadiness),
            'identity' => $guestArguments[3],
            'checked_at' => '2026-08-29T12:34:56+00:00',
            'expected' => 'healthy',
            'observed' => 'healthy',
            'evidence_ref' => 'incus://'.$guestArguments[4].'/'.$guestArguments[1],
        ], JSON_THROW_ON_ERROR));
    }

    if (($guestArguments[0] ?? null) === 'cat' || ($guestArguments[0] ?? null) === 'sha256sum') {
        return Process::result(exitCode: 1);
    }

    return Process::result();
}

/**
 * A detached worktree whose second commit changes the prepared state, a promoted
 * old generation, one recorded rollback generation, and one stale manifest.
 *
 * @return array{sourceRoot: string, worktree: string, branch: string, processes: ProcessFactory, oldSha: string, newSha: string, paths: StatePaths, state: AtomicJsonStore, manifests: StandbyManifestStore}
 */
function refreshFixture(): array
{
    $sourceRoot = dirname(__DIR__, 4);
    $worktree = temporaryPath('orbit-refresh-fixture-', 4);
    $branch = 'refresh-fixture-'.bin2hex(random_bytes(6));
    $processes = new ProcessFactory;
    expect(
        $processes->run(['git', '-C', $sourceRoot, 'worktree', 'add', '--detach', $worktree, 'HEAD'])->successful(),
    )->toBeTrue();
    expect($processes->run(['git', '-C', $worktree, 'switch', '-c', $branch])->successful())->toBeTrue();
    copyPreparedStateManifest($worktree);
    $git = new GitRepository($worktree);
    $manifestPath = $worktree.'/apps/e2e/resources/prepared-state.json';
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    unset($manifest['laravel_pin']);
    expect(file_put_contents(
        $manifestPath,
        json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n",
    ))->not->toBeFalse();
    refreshFixtureCommit($processes, $worktree, [$manifestPath], 'refresh fixture baseline');
    $oldSha = $git->commit('HEAD');
    $release = new LaravelRelease('v13.10.1', str_repeat('e', 40));
    $oldFingerprint = new PreparedStateFingerprint($git)->forCommit($oldSha, $release);
    $oldStructuralFingerprint = new PreparedStateFingerprint($git)->forCommit($oldSha);
    $rendererPath = $worktree.'/apps/gateway/app/Infrastructure/Metrics/MetricsPublicationRenderer.php';
    expect(file_put_contents($rendererPath, "\n", FILE_APPEND))->not->toBeFalse();
    refreshFixtureCommit($processes, $worktree, [$rendererPath], 'refresh fixture change');
    $newSha = $git->commit('HEAD');
    $paths = new StatePaths(temporaryPath('orbit-refresh-fixture-state-', 4));
    $state = new AtomicJsonStore($paths);
    $manifests = new StandbyManifestStore($state, $paths, new IncusHost);
    $generation = static fn (
        string $id,
        string $snapshotPrefix,
        ?string $previous = null,
    ): StandbyGeneration => new StandbyGeneration(
        $id,
        $oldSha,
        [
            'gateway' => "main-{$snapshotPrefix}-gateway",
            'app-dev' => "main-{$snapshotPrefix}-app-dev",
            'app-prod' => "main-{$snapshotPrefix}-app-prod",
        ],
        $oldFingerprint->value,
        str_repeat('d', 64),
        $release,
        $oldStructuralFingerprint->value,
        $oldStructuralFingerprint->manifest['schema'],
        $oldStructuralFingerprint->manifest['cold_epoch'],
        $oldStructuralFingerprint->manifest['base_image_alias'],
        $oldStructuralFingerprint->manifest['topology']['profile'],
        $oldStructuralFingerprint->manifest['topology']['roles'],
        $oldStructuralFingerprint->manifest['topology']['checkout_roles'],
        $previous,
    );
    $manifests->promote($generation('old-generation', 'old', 'rollback-generation'));
    $manifests->record($generation('rollback-generation', 'rollback'));
    $manifests->record($generation('stale-generation', 'stale'));

    return [
        'sourceRoot' => $sourceRoot,
        'worktree' => $worktree,
        'branch' => $branch,
        'processes' => $processes,
        'oldSha' => $oldSha,
        'newSha' => $newSha,
        'paths' => $paths,
        'state' => $state,
        'manifests' => $manifests,
    ];
}

/** @param array{manifests: StandbyManifestStore} $fixture */
function promoteLegacyRefreshGeneration(array $fixture): StandbyGeneration
{
    $current = $fixture['manifests']->promoted();
    expect($current)->not->toBeNull();
    $legacy = $current->toArray();
    $legacy['schema'] = StandbyGeneration::LEGACY_SCHEMA;
    $legacy['prepared_schema'] = 1;
    unset($legacy['topology']['assignments']);
    $generation = StandbyGeneration::fromArray($legacy);
    $fixture['manifests']->promote($generation);

    return $generation;
}

/** @param list<string> $paths */
function refreshFixtureCommit(ProcessFactory $processes, string $worktree, array $paths, string $message): void
{
    expect($processes->run(['git', '-C', $worktree, 'add', ...$paths])->successful())->toBeTrue();
    expect(
        $processes->run([
            'git',
            '-C',
            $worktree,
            '-c',
            'user.name=Test',
            '-c',
            'user.email=test@example.test',
            'commit',
            '-q',
            '-m',
            $message,
        ])->successful(),
    )->toBeTrue();
}

function copyPreparedStateManifest(string $worktree): void
{
    $source = dirname(__DIR__, 3).'/resources/prepared-state.json';
    $destination = $worktree.'/apps/e2e/resources/prepared-state.json';

    expect(copy($source, $destination))->toBeTrue();
}

/** @param array{sourceRoot: string, worktree: string, branch: string, processes: ProcessFactory} $fixture */
function removeRefreshFixture(array $fixture): void
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
    $fixture['processes']->run(['git', '-C', $fixture['sourceRoot'], 'branch', '-D', $fixture['branch']]);
}

/** @mago-expect lint:cyclomatic-complexity,halstead Test cases share one contract fixture and remain independently asserted. */
/** The host holds this checkout's standby VMs, but no snapshot the manifest names. */
function staleManifestProcess(PendingProcess $process, ProcessFactory $real, array &$mutations): ProcessResult
{
    $command = $process->command;
    assert(is_array($command), 'Incus uses argument arrays.');
    if (($command[0] ?? null) === 'git') {
        $path = (string) $process->path;

        return ($path === '' ? $real : $real->path($path))->input($process->input)->run($command);
    }
    if (in_array('image', $command, true)) {
        return Process::result(json_encode([[
            'type' => 'virtual-machine',
            'fingerprint' => str_repeat('b', 64),
            'aliases' => [['name' => 'orbit-base-ubuntu-26.04-runtime']],
        ]], JSON_THROW_ON_ERROR));
    }
    if (in_array('snapshot', $command, true) && in_array('list', $command, true)) {
        return Process::result('[]');
    }
    if (($command[3] ?? null) === 'list') {
        return Process::result(json_encode(
            array_map(static fn (string $instance): array => [
                'name' => $instance,
                'type' => 'virtual-machine',
                'status' => 'Stopped',
                'status_code' => 102,
                'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                'devices' => ['root' => ['pool' => 'orbit-e2e']],
            ], array_map(
                TopologyTarget::standby()->instance(...),
                TopologyProfile::ROLES,
            )),
            JSON_THROW_ON_ERROR,
        ));
    }
    $mutations[] = implode(' ', $command);

    return Process::result();
}

describe('StandbyRefresher contracts', function () {
    beforeEach(function () {
        $container = new Container;
        $container->instance(ProcessFactory::class, new ProcessFactory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    });

    it('returns terminal failure when the standby refresh lock is held', function () {
        $paths = new StatePaths(temporaryPath('orbit-refresh-lock-', 4));
        $state = new AtomicJsonStore($paths);
        $manifests = new StandbyManifestStore($state, $paths, new IncusHost);
        $root = dirname(__DIR__, 4);
        $host = new IncusHost(pool: 'orbit-e2e');
        $git = new GitRepository($root);
        $synchronizer = new WorktreeSynchronizer($host, $root, new OperationId(str_repeat('a', 32)));
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
            $root,
            StandbyIdentity::primary(),
        );
        $requestLock = new OperationLock($paths);
        $holder = new OperationLock($paths);
        $holder->acquire('standby-refresh', new OperationId(str_repeat('a', 32)), timeoutSeconds: 0);

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
                new OperationLock($paths),
                $state,
                $git,
                $root,
                new OperationId(str_repeat('a', 32)),
                StandbyIdentity::primary(),
                new StandbyAvailability($host, StandbyIdentity::primary()),
                0,
            );
            $result = $refresher->request(str_repeat('b', 40));

            expect($result->state)
                ->toBe('failed')
                ->and($result->error)
                ->toBe('Unable to acquire the standby refresh lock.');
        } finally {
            $holder->release();
        }
    });

    it('keeps refresh result identities exact', function () {
        $result = new RefreshResult('promoted', str_repeat('b', 32), 'generation-1');

        expect($result->toArray()['state'])
            ->toBe('promoted')
            ->and($result->successful())
            ->toBeTrue()
            ->and(new RefreshResult('unchanged', str_repeat('b', 32), 'generation-1')->successful())
            ->toBeTrue()
            ->and(new RefreshResult('failed', str_repeat('b', 32), error: 'boom')->successful())
            ->toBeFalse();
    });

    it('rejects cold construction before Incus or upstream release lookups', function () {
        $sourceRoot = dirname(__DIR__, 4);
        $worktree = temporaryPath('orbit-refresh-cold-permission-', 4);
        $branch = 'cold-permission-test-'.bin2hex(random_bytes(6));
        $processes = new ProcessFactory;
        expect(
            $processes->run(['git', '-C', $sourceRoot, 'worktree', 'add', '--detach', $worktree, 'HEAD'])->successful(),
        )->toBeTrue();

        try {
            expect($processes->run(['git', '-C', $worktree, 'switch', '-c', $branch])->successful())->toBeTrue();
            copyPreparedStateManifest($worktree);
            $manifestPath = $worktree.'/apps/e2e/resources/prepared-state.json';
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            unset($manifest['laravel_pin']);
            expect(file_put_contents(
                $manifestPath,
                json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n",
            ))->not->toBeFalse();
            expect($processes->run(['git', '-C', $worktree, 'add', $manifestPath])->successful())->toBeTrue();
            expect(
                $processes->run([
                    'git',
                    '-C',
                    $worktree,
                    '-c',
                    'user.name=Test',
                    '-c',
                    'user.email=test@example.test',
                    'commit',
                    '-q',
                    '-m',
                    'cold permission fixture',
                ])->successful(),
            )->toBeTrue();
            $externalCommands = [];
            Process::fake(function (PendingProcess $process) use (
                &$externalCommands,
                $processes,
                $worktree,
            ): ProcessResult {
                $command = $process->command;
                assert(is_array($command), 'External commands use argument arrays.');
                if (($command[0] ?? null) === 'git' && ($command[1] ?? null) !== 'ls-remote') {
                    return $processes->path($worktree)->run($command);
                }

                $externalCommands[] = $command;
                if (($command[0] ?? null) === 'git' && ($command[1] ?? null) === 'ls-remote') {
                    return Process::result(str_repeat('e', 40)."\trefs/tags/v13.0.0\n");
                }
                if (($command[3] ?? null) === 'image') {
                    return Process::result(json_encode([[
                        'type' => 'virtual-machine',
                        'fingerprint' => str_repeat('d', 64),
                        'aliases' => [['name' => 'orbit-base-ubuntu-26.04-runtime']],
                    ]], JSON_THROW_ON_ERROR));
                }

                throw new RuntimeException('Unexpected external command: '.implode(' ', $command));
            });
            $paths = new StatePaths(
                temporaryPath('orbit-refresh-cold-permission-state-', 4),
            );
            $result = standbyRefresherForPowerTests(
                new IncusHost(pool: 'orbit-e2e'),
                paths: $paths,
                repositoryRoot: $worktree,
            )->request(new GitRepository($worktree)->commit());

            expect($result->state)
                ->toBe('failed')
                ->and($result->error)
                ->toBe('Cold standby construction requires explicit permission.')
                ->and($externalCommands)
                ->toBeEmpty();
        } finally {
            $processes->run(['git', '-C', $sourceRoot, 'worktree', 'remove', '--force', $worktree]);
            $processes->run(['git', '-C', $sourceRoot, 'branch', '-D', $branch]);
        }
    });

    it('promotes a refreshed generation and prunes the stale manifests', function () {
        $fixture = refreshFixture();

        try {
            $processState = refreshProcessState($fixture['paths']);
            Process::fake(fn (PendingProcess $process): ProcessResult => refreshProcess(
                $process,
                $processState,
                $fixture['processes'],
                $fixture['oldSha'],
            ));

            $result = standbyRefresherForPowerTests(
                new IncusHost(pool: 'orbit-e2e'),
                $fixture['state'],
                $fixture['manifests'],
                $fixture['paths'],
                $fixture['worktree'],
            )->request($fixture['newSha']);
            $promoted = $fixture['manifests']->promoted();

            expect($result->state)->toBe('promoted');
            expect($processState->events)->toBe([
                'restore:orbit-e2e-standby-gateway/main-old-gateway',
                'restore:orbit-e2e-standby-app-dev/main-old-app-dev',
                'restore:orbit-e2e-standby-app-prod/main-old-app-prod',
                'start:local:orbit-e2e-standby-gateway',
                'start:local:orbit-e2e-standby-app-dev',
                'start:local:orbit-e2e-standby-app-prod',
                'agent:local:orbit-e2e-standby-gateway',
                'agent:local:orbit-e2e-standby-app-dev',
                'agent:local:orbit-e2e-standby-app-prod',
                'ipv4:local:orbit-e2e-standby-gateway',
                'ipv4:local:orbit-e2e-standby-app-dev',
                'ipv4:local:orbit-e2e-standby-app-prod',
                'convergence',
                'readiness',
                'proof',
                'snapshot',
            ]);
            expect($promoted?->id)
                ->toBe($result->generationId)
                ->and($promoted?->previousGenerationId)
                ->toBe('old-generation')
                ->and($processState->pruneLockResults)
                ->toBe([false, false, false, true, true, true])
                ->and($fixture['state']->read('standby/generations/stale-generation.json'))
                ->toBeNull();
        } finally {
            removeRefreshFixture($fixture);
        }
    });

    it('migrates a matching schema 4 generation instead of returning it unchanged', function () {
        $fixture = refreshFixture();

        try {
            promoteLegacyRefreshGeneration($fixture);
            expect(
                $fixture['processes']->run([
                    'git',
                    '-C',
                    $fixture['worktree'],
                    'switch',
                    '--detach',
                    $fixture['oldSha'],
                ])->successful(),
            )->toBeTrue();
            $processState = refreshProcessState($fixture['paths']);
            Process::fake(fn (PendingProcess $process): ProcessResult => refreshProcess(
                $process,
                $processState,
                $fixture['processes'],
                $fixture['oldSha'],
            ));

            $result = standbyRefresherForPowerTests(
                new IncusHost(pool: 'orbit-e2e'),
                $fixture['state'],
                $fixture['manifests'],
                $fixture['paths'],
                $fixture['worktree'],
            )->request($fixture['oldSha']);
            $promoted = $fixture['manifests']->promoted();

            expect($result->state)
                ->toBe('promoted')
                ->and($promoted?->isLegacy())
                ->toBeFalse()
                ->and($promoted?->preparedSchema)
                ->toBe(2)
                ->and($promoted?->topologyAssignments)
                ->toBe(TopologyProfile::ASSIGNMENTS)
                ->and($promoted?->previousGenerationId)
                ->toBe('old-generation')
                ->and($processState->events)
                ->toContain('convergence', 'readiness', 'proof', 'snapshot');
        } finally {
            removeRefreshFixture($fixture);
        }
    });

    it('keeps a schema 4 generation promoted when migration verification fails', function () {
        $fixture = refreshFixture();

        try {
            $legacy = promoteLegacyRefreshGeneration($fixture);
            expect(
                $fixture['processes']->run([
                    'git',
                    '-C',
                    $fixture['worktree'],
                    'switch',
                    '--detach',
                    $fixture['oldSha'],
                ])->successful(),
            )->toBeTrue();
            $processState = refreshProcessState($fixture['paths'], failReadiness: true);
            Process::fake(fn (PendingProcess $process): ProcessResult => refreshProcess(
                $process,
                $processState,
                $fixture['processes'],
                $fixture['oldSha'],
            ));

            $result = standbyRefresherForPowerTests(
                new IncusHost(pool: 'orbit-e2e'),
                $fixture['state'],
                $fixture['manifests'],
                $fixture['paths'],
                $fixture['worktree'],
            )->request($fixture['oldSha']);

            expect($result->state)
                ->toBe('failed')
                ->and($result->error)
                ->toBe('Standby verification failed.')
                ->and($result->generationId)
                ->toBe('old-generation')
                ->and($fixture['manifests']->promoted()?->toArray())
                ->toBe($legacy->toArray())
                ->and($fixture['manifests']->promoted()?->isLegacy())
                ->toBeTrue()
                ->and($fixture['state']->read('standby/corrupt.json'))
                ->toBeNull();
        } finally {
            removeRefreshFixture($fixture);
        }
    });

    it('restores the promoted snapshot when the refreshed standby fails verification', function () {
        $fixture = refreshFixture();

        try {
            $processState = refreshProcessState($fixture['paths'], failReadiness: true);
            Process::fake(fn (PendingProcess $process): ProcessResult => refreshProcess(
                $process,
                $processState,
                $fixture['processes'],
                $fixture['oldSha'],
            ));

            $result = standbyRefresherForPowerTests(
                new IncusHost(pool: 'orbit-e2e'),
                $fixture['state'],
                $fixture['manifests'],
                $fixture['paths'],
                $fixture['worktree'],
            )->request($fixture['newSha']);

            $readiness = array_search('readiness', $processState->events, true);
            expect($result->state)
                ->toBe('failed')
                ->and($result->error)
                ->toBe('Standby verification failed.')
                ->and($result->generationId)
                ->toBe('old-generation')
                ->and($readiness)
                ->toBeInt()
                ->and(array_slice($processState->events, $readiness + 1))
                ->toBe([
                    'restore:orbit-e2e-standby-gateway/main-old-gateway',
                    'restore:orbit-e2e-standby-app-dev/main-old-app-dev',
                    'restore:orbit-e2e-standby-app-prod/main-old-app-prod',
                ])
                ->and($processState->events)
                ->not
                ->toContain('proof')
                ->and($processState->snapshots)
                ->toBe([])
                ->and($fixture['manifests']->promoted()?->id)
                ->toBe('old-generation')
                ->and($fixture['state']->read('standby/corrupt.json'))
                ->toBeNull();
        } finally {
            removeRefreshFixture($fixture);
        }
    });

    it('confirms an unchanged generation is stopped', function () {
        $sourceRoot = dirname(__DIR__, 4);
        $cleanRoot = temporaryPath('orbit-refresh-worktree-', 4);
        $branch = 'refresh-test-'.bin2hex(random_bytes(6));
        $processes = new ProcessFactory;
        $worktree = $processes->run(['git', '-C', $sourceRoot, 'worktree', 'add', '--detach', $cleanRoot, 'HEAD']);
        expect($worktree->successful())->toBeTrue();

        try {
            expect($processes->run(['git', '-C', $cleanRoot, 'switch', '-c', $branch])->successful())->toBeTrue();
            copyPreparedStateManifest($cleanRoot);
            $git = new GitRepository($cleanRoot);
            $manifestPath = $cleanRoot.'/apps/e2e/resources/prepared-state.json';
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            unset($manifest['laravel_pin']);
            file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
            expect($processes->run(['git', '-C', $cleanRoot, 'add', $manifestPath])->successful())->toBeTrue();
            expect(
                $processes->run([
                    'git',
                    '-C',
                    $cleanRoot,
                    '-c',
                    'user.name=Test',
                    '-c',
                    'user.email=test@example.test',
                    'commit',
                    '--allow-empty',
                    '-q',
                    '-m',
                    'prepared fixture baseline',
                ])->successful(),
            )->toBeTrue();
            $mainSha = $git->commit('HEAD');
            $release = new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0');
            $prepared = new PreparedStateFingerprint($git)->forCommit($mainSha, $release);
            $structural = new PreparedStateFingerprint($git)->forCommit($mainSha);
            $paths = new StatePaths(temporaryPath('orbit-refresh-stopped-', 4));
            $state = new AtomicJsonStore($paths);
            $manifests = new StandbyManifestStore($state, $paths, new IncusHost);
            $manifests->promote(new \App\E2E\Value\StandbyGeneration(
                'stopped-test',
                $mainSha,
                ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
                $prepared->value,
                str_repeat('b', 64),
                $release,
                $structural->value,
                $structural->manifest['schema'],
                $structural->manifest['cold_epoch'],
                $structural->manifest['base_image_alias'],
                $structural->manifest['topology']['profile'],
                $structural->manifest['topology']['roles'],
                $structural->manifest['topology']['checkout_roles'],
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
                2,
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
            Process::assertDidntRun(
                fn (PendingProcess $process): bool => (
                    is_array($process->command)
                    && ($process->command[3] ?? null) === 'image'
                ),
            );
        } finally {
            $processes->run(['git', '-C', $sourceRoot, 'worktree', 'remove', '--force', $cleanRoot]);
            $processes->run(['git', '-C', $sourceRoot, 'branch', '-D', $branch]);
        }
    });

    it('requires cold recovery before mutating a promoted standby', function () {
        $sourceRoot = dirname(__DIR__, 4);
        $worktree = temporaryPath('orbit-refresh-cold-', 4);
        $branch = 'cold-test-'.bin2hex(random_bytes(6));
        $processes = new ProcessFactory;
        expect(
            $processes->run(['git', '-C', $sourceRoot, 'worktree', 'add', '--detach', $worktree, 'HEAD'])->successful(),
        )->toBeTrue();
        try {
            $git = new GitRepository($worktree);
            expect($processes->run(['git', '-C', $worktree, 'switch', '-c', $branch])->successful())->toBeTrue();
            copyPreparedStateManifest($worktree);
            $manifestPath = $worktree.'/apps/e2e/resources/prepared-state.json';
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            unset($manifest['laravel_pin']);
            file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
            expect($processes->run(['git', '-C', $worktree, 'add', $manifestPath])->successful())->toBeTrue();
            expect(
                $processes->run([
                    'git',
                    '-C',
                    $worktree,
                    '-c',
                    'user.name=Test',
                    '-c',
                    'user.email=test@example.test',
                    'commit',
                    '--allow-empty',
                    '-q',
                    '-m',
                    'prepared fixture baseline',
                ])->successful(),
            )->toBeTrue();
            $oldSha = $git->commit('HEAD');
            $release = new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0');
            $oldFingerprint = new PreparedStateFingerprint($git)->forCommit($oldSha, $release);
            $oldStructuralFingerprint = new PreparedStateFingerprint($git)->forCommit($oldSha);
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            $manifest['cold_epoch'] = 'ubuntu-25.04-amd64-v1';
            file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
            expect($processes->run(['git', '-C', $worktree, 'add', $manifestPath])->successful())->toBeTrue();
            expect(
                $processes->run([
                    'git',
                    '-C',
                    $worktree,
                    '-c',
                    'user.name=Test',
                    '-c',
                    'user.email=test@example.test',
                    'commit',
                    '-q',
                    '-m',
                    'cold change',
                ])->successful(),
            )->toBeTrue();
            $newSha = $git->commit('HEAD');
            $paths = new StatePaths(temporaryPath('orbit-refresh-cold-state-', 4));
            $state = new AtomicJsonStore($paths);
            $manifests = new StandbyManifestStore($state, $paths, new IncusHost);
            $generation = new \App\E2E\Value\StandbyGeneration(
                'old-generation',
                $oldSha,
                ['gateway' => 'main-old-gateway', 'app-dev' => 'main-old-app-dev', 'app-prod' => 'main-old-app-prod'],
                $oldFingerprint->value,
                str_repeat('d', 64),
                $release,
                $oldStructuralFingerprint->value,
                $oldStructuralFingerprint->manifest['schema'],
                $oldStructuralFingerprint->manifest['cold_epoch'],
                $oldStructuralFingerprint->manifest['base_image_alias'],
                $oldStructuralFingerprint->manifest['topology']['profile'],
                $oldStructuralFingerprint->manifest['topology']['roles'],
                $oldStructuralFingerprint->manifest['topology']['checkout_roles'],
            );
            $manifests->promote($generation);
            $refresher = standbyRefresherForPowerTests(
                new IncusHost(pool: 'orbit-e2e'),
                $state,
                $manifests,
                $paths,
                $worktree,
            );
            $result = $refresher->request($newSha);
            expect($result->state)
                ->toBe('failed')
                ->and($result->error)
                ->toBe('Cold base changed; recovery-required cold standby rebuild.')
                ->and($manifests->promoted()->toArray())
                ->toEqual($generation->toArray());
            Process::assertDidntRun(
                fn (PendingProcess $process): bool => (
                    is_array($process->command) && in_array('incus', $process->command, true)
                ),
            );
        } finally {
            expect(
                $processes->run(['git', '-C', $sourceRoot, 'worktree', 'remove', '--force', $worktree])->successful(),
            )->toBeTrue();
            expect($processes->run(['git', '-C', $sourceRoot, 'branch', '-D', $branch])->successful())->toBeTrue();
        }
    });

    it('cleans every partial candidate snapshot and retries the same generation', function () {
        $processState = candidateSnapshotProcessState();
        Process::fake(fn (PendingProcess $process): ProcessResult => candidateSnapshotProcess($process, $processState));

        $refresher = standbyRefresherForPowerTests(new IncusHost(pool: 'orbit-e2e'));
        $snapshot = new ReflectionMethod($refresher, 'snapshot');
        $mainSha = str_repeat('a', 40);
        $fingerprint = str_repeat('b', 64);
        $release = new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0');
        $manifest = [
            'schema' => 2,
            'cold_epoch' => 'ubuntu-26.04-amd64-v1',
            'base_image_alias' => 'orbit-base-ubuntu-26.04-runtime',
            'topology' => [
                'profile' => 'gateway_app-dev_app-prod',
                'roles' => ['gateway', 'app-dev', 'app-prod'],
                'checkout_roles' => ['gateway', 'app-dev'],
                'assignments' => TopologyProfile::ASSIGNMENTS,
            ],
        ];

        try {
            $snapshot->invoke(
                $refresher,
                $mainSha,
                $fingerprint,
                str_repeat('c', 64),
                $release,
                null,
                str_repeat('d', 64),
                $manifest,
            );
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }

        expect($failure ?? null)
            ->not
            ->toBeNull()
            ->and($failure->getMessage())
            ->toContain('candidate create failed', 'controlled cleanup failure')
            ->and($processState->deleted)
            ->toContain('orbit-e2e-standby-gateway', 'orbit-e2e-standby-app-dev');

        $generation = $snapshot->invoke(
            $refresher,
            $mainSha,
            $fingerprint,
            str_repeat('c', 64),
            $release,
            null,
            str_repeat('d', 64),
            $manifest,
        );

        expect($generation->id)
            ->toBe(str_repeat('a', 12).'-'.str_repeat('b', 12))
            ->and(array_keys($processState->existingSnapshots))
            ->toEqual([
                'orbit-e2e-standby-gateway',
                'orbit-e2e-standby-app-dev',
                'orbit-e2e-standby-app-prod',
            ]);
    });

    it('refuses to return a generation until every candidate snapshot is observable', function () {
        $created = [];
        /** @mago-expect lint:cyclomatic-complexity Candidate snapshot responses stay in one protocol fixture. */
        Process::fake(function (PendingProcess $process) use (&$created): ProcessResult {
            $command = $process->command;
            assert(is_array($command), 'Incus uses argument arrays.');
            if (($command[3] ?? null) === 'list') {
                return candidateSnapshotVm($command);
            }
            if (($command[3] ?? null) === 'snapshot' && ($command[4] ?? null) === 'list') {
                $instance = preg_replace('/\A[^:]+:/', '', $command[5] ?? '');
                $snapshot = $created[$instance] ?? null;

                return Process::result(
                    $snapshot === null
                        ? '[]'
                        : json_encode([[
                            'name' => $snapshot,
                            'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                        ]], JSON_THROW_ON_ERROR),
                );
            }
            if (($command[3] ?? null) === 'snapshot' && ($command[4] ?? null) === 'create') {
                $instance = preg_replace('/\A[^:]+:/', '', $command[5] ?? '');
                if (! str_ends_with($instance, '-app-prod')) {
                    $created[$instance] = $command[6];
                }

                return Process::result();
            }
            if (($command[3] ?? null) === 'snapshot' && ($command[4] ?? null) === 'delete') {
                $instance = preg_replace('/\A[^:]+:/', '', $command[5] ?? '');
                unset($created[$instance]);

                return Process::result();
            }

            throw new RuntimeException('Unexpected Incus command: '.implode(' ', $command));
        });
        $refresher = standbyRefresherForPowerTests(new IncusHost(pool: 'orbit-e2e'));
        $snapshot = new ReflectionMethod($refresher, 'snapshot');
        $manifest = [
            'schema' => 2,
            'cold_epoch' => 'ubuntu-26.04-amd64-v1',
            'base_image_alias' => 'orbit-base-ubuntu-26.04-runtime',
            'topology' => [
                'profile' => 'gateway_app-dev_app-prod',
                'roles' => ['gateway', 'app-dev', 'app-prod'],
                'checkout_roles' => ['gateway', 'app-dev'],
                'assignments' => TopologyProfile::ASSIGNMENTS,
            ],
        ];

        expect(fn () => $snapshot->invoke(
            $refresher,
            str_repeat('a', 40),
            str_repeat('b', 64),
            str_repeat('c', 64),
            new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0'),
            null,
            str_repeat('d', 64),
            $manifest,
        ))
            ->toThrow(RuntimeException::class, 'snapshots do not exist');
    });

    it('clears the corrupt marker only after an exact restore succeeds', function () {
        $paths = new StatePaths(temporaryPath('orbit-refresher-', 4));
        $state = new AtomicJsonStore($paths);
        $manifests = new StandbyManifestStore($state, $paths, new IncusHost);
        $state->write('standby/corrupt.json', ['schema' => 1, 'message' => 'restore required']);
        $generation = new \App\E2E\Value\StandbyGeneration(
            'g-'.str_repeat('a', 12),
            str_repeat('b', 40),
            ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
            str_repeat('c', 64),
            str_repeat('d', 64),
            new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0'),
            str_repeat('e', 64),
            2,
            'ubuntu-26.04-amd64-v1',
            'orbit-base-ubuntu-26.04-runtime',
            'gateway_app-dev_app-prod',
            ['gateway', 'app-dev', 'app-prod'],
            ['gateway', 'app-dev'],
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
                    'created_at' => '2026-01-01T00:00:00Z',
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                ]], JSON_THROW_ON_ERROR));
            }
            if (in_array('list', $command, true)) {
                $name = preg_replace('/\A[^:]+:/', '', $command[4] ?? '');
                $names = $name === ''
                    ? array_map(TopologyTarget::standby()->instance(...), TopologyProfile::ROLES)
                    : [$name];

                return Process::result(json_encode(array_map(static fn (string $instance): array => [
                    'name' => $instance,
                    'type' => 'virtual-machine',
                    'status' => 'Stopped',
                    'status_code' => 102,
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                    'devices' => ['root' => ['pool' => 'orbit-e2e']],
                ], $names), JSON_THROW_ON_ERROR));
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

    /** @mago-expect lint:cyclomatic-complexity Restore scenario asserts the complete ordered cleanup contract. */
    it('deletes unpromoted candidate snapshots before restoring the promoted generation', function () {
        [$paths, $state, $manifests] = standbyRestoreFixture();
        $generation = standbyRestoreGeneration();
        $manifests->record($generation);
        $orphans = array_fill_keys(array_map(
            TopologyTarget::standby()->instance(...),
            TopologyProfile::ROLES,
        ), true);
        $events = [];
        /**
         * @mago-expect lint:cyclomatic-complexity The process fake preserves its ordered inventory branches.
         * @mago-expect lint:cyclomatic-complexity The process fake preserves its ordered snapshot branches.
         */
        $processFake = function (PendingProcess $process) use (&$orphans, &$events, $generation) {
            $command = $process->command;
            assert(is_array($command));
            if (($command[3] ?? null) === 'list') {
                $name = preg_replace('/\A[^:]+:/', '', (string) ($command[4] ?? ''));
                $names = $name === ''
                    ? array_map(TopologyTarget::standby()->instance(...), TopologyProfile::ROLES)
                    : [$name];

                return Process::result(json_encode(array_map(static fn (string $instance): array => [
                    'name' => $instance,
                    'type' => 'virtual-machine',
                    'status' => 'Stopped',
                    'status_code' => 102,
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                    'devices' => ['root' => ['pool' => 'orbit-e2e']],
                ], $names), JSON_THROW_ON_ERROR));
            }
            if (($command[3] ?? null) === 'snapshot' && ($command[4] ?? null) === 'list') {
                $instance = preg_replace('/\A[^:]+:/', '', (string) ($command[5] ?? ''));
                $role = str_replace('orbit-e2e-standby-', '', $instance);
                $snapshots = [[
                    'name' => $generation->snapshots[$role],
                    'created_at' => '2026-01-01T00:00:00Z',
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                ]];
                if ($orphans[$instance] ?? false) {
                    $snapshots[] = [
                        'name' => 'main-interrupted-candidate',
                        'created_at' => '2026-01-02T00:00:00Z',
                        'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                    ];
                    $snapshots[] = [
                        'name' => 'main-z-old-name',
                        'created_at' => '2026-01-03T00:00:00Z',
                        'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                    ];
                }

                return Process::result(json_encode($snapshots, JSON_THROW_ON_ERROR));
            }
            if (($command[3] ?? null) === 'snapshot' && ($command[4] ?? null) === 'delete') {
                $instance = preg_replace('/\A[^:]+:/', '', (string) ($command[5] ?? ''));
                unset($orphans[$instance]);
                $events[] = 'delete:'.$instance.'/'.(string) $command[array_key_last($command)];

                return Process::result();
            }
            if (($command[3] ?? null) === 'snapshot' && ($command[4] ?? null) === 'restore') {
                if ($orphans !== []) {
                    return Process::result('', 'cannot restore with subsequent snapshots', 1);
                }
                $events[] = 'restore:'.($command[5] ?? '');

                return Process::result();
            }

            return Process::result('', 'unexpected command', 1);
        };
        Process::fake($processFake);

        $restored = standbyRefresherForPowerTests(
            new IncusHost(pool: 'orbit-e2e'),
            $state,
            $manifests,
            $paths,
        )->restore();

        expect($restored)
            ->toEqual($generation)
            ->and(array_slice($events, 0, 3))
            ->each->toStartWith('delete:')->and(array_filter(
                array_slice($events, 0, 6),
                static fn (string $event): bool => str_contains($event, 'main-z-old-name'),
            ))->toHaveCount(3)->and(array_slice($events, 3))
            ->each->toStartWith('restore:');
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
    it('names a recovery command, and marks nothing corrupt, when the manifest is stale', function () {
        $fixture = refreshFixture();

        try {
            $mutations = [];
            $real = new ProcessFactory;
            Process::fake(
                fn (PendingProcess $process): ProcessResult => staleManifestProcess(
                    $process,
                    $real,
                    $mutations,
                ),
            );

            $result = standbyRefresherForPowerTests(
                new IncusHost(pool: 'orbit-e2e'),
                $fixture['state'],
                $fixture['manifests'],
                $fixture['paths'],
                $fixture['worktree'],
            )->request($fixture['newSha']);

            expect($result->state)
                ->toBe('failed')
                ->and($result->error)
                ->toContain('bin/e2e-standby rebuild')
                ->and($result->error)
                ->toContain('orbit-e2e-standby-gateway')
                ->and($result->error)
                ->toContain('old-generation is stale')
                ->and($fixture['state']->read('standby/corrupt.json'))
                ->toBeNull()
                ->and($fixture['manifests']->promoted()?->id)
                ->toBe('old-generation')
                ->and($mutations)
                ->toBe([]);
        } finally {
            removeRefreshFixture($fixture);
        }
    });
});
