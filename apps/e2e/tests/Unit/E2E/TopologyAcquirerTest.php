<?php

declare(strict_types=1);

use App\E2E\AcquisitionRollback;
use App\E2E\Git\GitRepository;
use App\E2E\IncusHost;
use App\E2E\PreparedStateFingerprint;
use App\E2E\StandbyManifestStore;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\TopologyAcquirer;
use App\E2E\TopologyConverger;
use App\E2E\TopologyManifestStore;
use App\E2E\TopologyVerifier;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\IncusInstance;
use App\E2E\Value\IncusNetwork;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\OperationId;
use App\E2E\Value\ProofResult;
use App\E2E\Value\SourceState;
use App\E2E\Value\StandbyGeneration;
use App\E2E\Value\TopologyRequest;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationReport;
use App\E2E\WorktreeSynchronizer;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Process;

uses(Tests\TestCase::class);

function taskNineAcquirer(
    string $repositoryRoot,
    StatePaths $paths,
    ?AcquisitionRollback $rollback = null,
    ?IncusHost $host = null,
): TopologyAcquirer {
    $store = new AtomicJsonStore($paths);
    $host ??= new IncusHost;

    return new TopologyAcquirer(
        $host,
        new PreparedStateFingerprint(new GitRepository($repositoryRoot)),
        new StandbyManifestStore($store, $paths),
        new TopologyManifestStore($store),
        new WorktreeSynchronizer($host, $repositoryRoot),
        new TopologyConverger($host),
        new TopologyVerifier($host),
        $store,
        $paths,
        $repositoryRoot,
        $rollback,
    );
}

function preparedTopologyRepository(): string
{
    $root = sys_get_temp_dir().'/orbit-prepared-topology-'.bin2hex(random_bytes(8));
    $e2e = dirname(__DIR__, 3);
    $manifestPath = 'apps/e2e/resources/prepared-state.json';
    mkdir($root.'/'.dirname($manifestPath), 0700, true);
    copy($e2e.'/resources/prepared-state.json', $root.'/'.$manifestPath);
    $manifest = json_decode((string) file_get_contents($root.'/'.$manifestPath), true, 512, JSON_THROW_ON_ERROR);

    foreach ($manifest['paths'] as $pattern) {
        if ($pattern === $manifestPath) {
            continue;
        }
        $path = str_replace(['**/', '*'], ['nested/', 'placeholder'], $pattern);
        $directory = $root.'/'.dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($root.'/'.$path, 'prepared');
    }
    $guestSource = $e2e.'/resources/guest';
    $guestTarget = $root.'/apps/e2e/resources/guest';
    foreach (glob($guestSource.'/*.sh') ?: [] as $script) {
        copy($script, $guestTarget.'/'.basename($script));
        chmod($guestTarget.'/'.basename($script), 0755);
    }

    foreach ([
        ['git', 'init', '-q', '-b', 'feature/NCK-123', $root],
        ['git', '-C', $root, 'config', 'user.email', 'developer@example.com'],
        ['git', '-C', $root, 'config', 'user.name', 'Orbit Developer'],
        ['git', '-C', $root, 'add', '.'],
        ['git', '-C', $root, 'commit', '-q', '-m', 'Prepared state'],
        ['git', '-C', $root, 'branch', 'main'],
    ] as $command) {
        if (! Process::run($command)->successful()) {
            throw new RuntimeException('Unable to prepare the topology fixture repository.');
        }
    }

    return $root;
}

function standbyVmInventoryJson(): string
{
    return json_encode(array_map(
        static fn (string $role): array => [
            'name' => TopologyTarget::standby()->instance($role),
            'type' => 'virtual-machine',
            'status' => 'Stopped',
            'status_code' => 102,
            'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
            'devices' => ['root' => ['pool' => 'default']],
        ],
        \App\E2E\Value\TopologyProfile::ROLES,
    ), JSON_THROW_ON_ERROR);
}

function standbySnapshotInventoryJson(string $instance, bool $include = true, string $owner = 'orbit-e2e'): string
{
    $role = str_replace('orbit-e2e-standby-', '', $instance);
    $snapshot = match ($role) {
        'gateway' => 'main-gateway',
        'app-dev' => 'main-app-dev',
        'app-prod' => 'main-app-prod',
        default => throw new RuntimeException('Unexpected standby fixture instance.'),
    };

    return json_encode(
        $include
            ? [[
                'name' => $snapshot,
                'config' => ['user.orbit.e2e.owner' => $owner],
            ]] : [],
        JSON_THROW_ON_ERROR,
    );
}

function topologyVmJson(string $name, array $metadata = ['user.orbit.e2e.owner' => 'orbit-e2e']): string
{
    return json_encode([[
        'name' => $name,
        'type' => 'virtual-machine',
        'status' => 'Stopped',
        'status_code' => 102,
        'config' => $metadata,
        'devices' => ['root' => ['pool' => 'default']],
    ]], JSON_THROW_ON_ERROR);
}

function preparedBaseImageJson(string $fingerprint): string
{
    return json_encode([[
        'type' => 'virtual-machine',
        'fingerprint' => $fingerprint,
        'aliases' => [['name' => 'orbit-base-ubuntu-26.04-runtime']],
    ]], JSON_THROW_ON_ERROR);
}

function preparedGenerationId(string $repositoryRoot, string $fingerprint): string
{
    return substr(new GitRepository($repositoryRoot)->commit(), 0, 12).'-'.substr($fingerprint, 0, 12);
}

function featureTopologyFixture(string $repositoryRoot, StatePaths $paths): void
{
    $store = new AtomicJsonStore($paths);
    $fingerprint = new PreparedStateFingerprint(new GitRepository($repositoryRoot))->forCommit();
    $target = new TopologyTarget('NCK-123');
    $generation = new StandbyGeneration(
        str_repeat('a', 12).'-'.substr($fingerprint->value, 0, 12),
        new GitRepository($repositoryRoot)->commit(),
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        $fingerprint->value,
        str_repeat('b', 64),
        new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0'),
    );
    new TopologyManifestStore($store)->write(new FeatureTopology(
        $target,
        $generation,
        $target->network(),
        [
            'gateway' => $target->instance('gateway'),
            'app-dev' => $target->instance('app-dev'),
            'app-prod' => $target->instance('app-prod'),
        ],
        new SourceState(new GitRepository($repositoryRoot)->commit(), new GitRepository($repositoryRoot)->commit()),
        new VerificationReport(true, ['fixture' => true]),
    ));
}

/** @param list<string> $mutations */
function identityRefreshRollback(
    TopologyTarget $target,
    ?string &$operationId,
    array &$mutations,
): AcquisitionRollback {
    return new AcquisitionRollback(
        function (string $resource) use ($target, &$operationId): IncusInstance|IncusNetwork {
            return (
                $resource === $target->network()
                    ? new IncusNetwork('local', 'default', $resource, [
                        'user.orbit.e2e.owner' => 'orbit-e2e',
                        'user.orbit.e2e.issue' => 'NCK-123',
                        'user.orbit.e2e.operation' => $operationId,
                    ])
                    : new IncusInstance('local', 'default', $resource, 'default', [
                        'user.orbit.e2e.owner' => 'orbit-e2e',
                        'user.orbit.e2e.issue' => 'NCK-123',
                        'user.orbit.e2e.operation' => $operationId,
                    ])
            );
        },
        function (string $resource) use (&$mutations): void {
            $mutations[] = 'stop:'.$resource;
        },
        function (string $resource) use (&$mutations): void {
            $mutations[] = 'delete:'.$resource;
            throw new RuntimeException('cleanup failed');
        },
        function (string $resource) use (&$mutations): void {
            $mutations[] = 'network:'.$resource;
        },
    );
}

/** @param list<string> $command */
function identityRefreshInventoryResult(
    array $command,
    TopologyTarget $target,
    ?string &$operationId,
): ?\Illuminate\Contracts\Process\ProcessResult {
    if (in_array('image', $command, true) && in_array('list', $command, true)) {
        return Process::result(preparedBaseImageJson(str_repeat('a', 64)));
    }
    if (in_array('network', $command, true) && in_array('list', $command, true)) {
        return Process::result(json_encode([[
            'name' => $target->network(),
            'config' => [
                'user.orbit.e2e.owner' => 'orbit-e2e',
                'user.orbit.e2e.issue' => 'NCK-123',
                'user.orbit.e2e.operation' => $operationId,
            ],
        ]], JSON_THROW_ON_ERROR));
    }
    if (in_array('list', $command, true) && ! in_array('snapshot', $command, true)) {
        $identity = (string) ($command[4] ?? '');
        $featurePrefix = 'local:orbit-e2e-nck-123-';
        if (str_starts_with($identity, $featurePrefix)) {
            $name = preg_replace('/\A[^:]+:/', '', $identity);

            return Process::result(topologyVmJson($name, [
                'user.orbit.e2e.owner' => 'orbit-e2e',
                'user.orbit.e2e.issue' => 'NCK-123',
                'user.orbit.e2e.operation' => $operationId,
            ]));
        }

        return Process::result(standbyVmInventoryJson());
    }
    if (in_array('snapshot', $command, true) && in_array('list', $command, true)) {
        $instance = preg_replace('/\A[^:]+:/', '', (string) ($command[5] ?? ''));

        return Process::result(standbySnapshotInventoryJson($instance));
    }

    return null;
}

/** @param list<string> $command @param list<string> $events */
function identityRefreshMutationResult(array $command, array &$events): \Illuminate\Contracts\Process\ProcessResult
{
    if (array_any(
        $command,
        fn (mixed $argument): bool => is_string($argument) && str_starts_with($argument, 'hwaddr='),
    )) {
        $events[] = 'mac';

        return Process::result();
    }
    if (in_array('start', $command, true)) {
        $events[] = 'start';

        return Process::result();
    }
    if (in_array('exec', $command, true) && in_array('/bin/true', $command, true)) {
        $events[] = 'readiness';

        return Process::result();
    }
    if (in_array('exec', $command, true) && in_array('sh', $command, true)) {
        $events[] = 'identity';

        return Process::result('', 'identity refresh failed', 1);
    }

    return Process::result();
}

/** @param list<string> $events */
function fakeIdentityRefreshFailure(
    string $repositoryRoot,
    TopologyTarget $target,
    array &$events,
    ?string &$operationId,
): void {
    $realProcess = new ProcessFactory;
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use (
        $target,
        $repositoryRoot,
        $realProcess,
        &$events,
        &$operationId,
    ) {
        $command = $process->command;
        if (($command[0] ?? null) === 'git') {
            return $realProcess->path($repositoryRoot)->run($command);
        }

        foreach ($command as $argument) {
            if (preg_match('/\Auser\.orbit\.e2e\.operation=([0-9a-f]{32})\z/', (string) $argument, $matches)) {
                $operationId = $matches[1];
            }
        }

        return (
            identityRefreshInventoryResult($command, $target, $operationId) ?? identityRefreshMutationResult(
                $command,
                $events,
            )
        );
    });
}

function pinnedFeatureWorktree(string $repositoryRoot, string $suffix, string $tag, string $commit): string
{
    $worktree = sys_get_temp_dir().'/orbit-worktree-'.$suffix.'-'.bin2hex(random_bytes(8));
    foreach ([
        ['git', '-C', $repositoryRoot, 'worktree', 'add', '-q', '-b', 'feature/NCK-123-'.$suffix, $worktree, 'HEAD'],
        ['git', '-C', $worktree, 'add', '.'],
    ] as $index => $command) {
        if ($index === 1) {
            $manifestPath = $worktree.'/apps/e2e/resources/prepared-state.json';
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            $manifest['laravel_pin'] = ['tag' => $tag, 'commit' => $commit];
            file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
        }
        if (! Process::run($command)->successful()) {
            throw new RuntimeException('Unable to prepare a pinned feature worktree.');
        }
    }
    if (! Process::run(['git', '-C', $worktree, 'commit', '-q', '-m', 'Pin Laravel'])->successful()) {
        throw new RuntimeException('Unable to commit the Laravel pin fixture.');
    }

    return $worktree;
}

/** @param list<string> $command */
function pinnedWorktreeInventoryResult(
    array $command,
    TopologyTarget $target,
): ?\Illuminate\Contracts\Process\ProcessResult {
    if (in_array('image', $command, true) && in_array('list', $command, true)) {
        return Process::result(preparedBaseImageJson(str_repeat('b', 64)));
    }
    if (($command[3] ?? null) === 'list') {
        $name = preg_replace('/\A[^:]+:/', '', (string) ($command[4] ?? ''));

        return Process::result(
            $name === $target->network()
                ? '[]'
                : topologyVmJson($name, ['user.orbit.e2e.owner' => 'orbit-e2e']),
        );
    }
    if (($command[3] ?? null) === 'network' && ($command[4] ?? null) === 'list') {
        return Process::result(json_encode([[
            'name' => $target->network(),
            'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
        ]], JSON_THROW_ON_ERROR));
    }
    if (($command[3] ?? null) === 'snapshot' && ($command[4] ?? null) === 'list') {
        $instance = preg_replace('/\A[^:]+:/', '', (string) ($command[5] ?? ''));

        return Process::result(standbySnapshotInventoryJson($instance));
    }

    return null;
}

/** @param list<string> $command */
function pinnedWorktreeGuestResult(array $command): \Illuminate\Contracts\Process\ProcessResult
{
    $guest = array_slice($command, 6);
    if (array_slice($guest, 0, 6) === ['runuser', '-u', 'orbit', '--', 'env', 'HOME=/home/orbit']) {
        $guest = array_slice($guest, 6);
    }
    if ($guest === ['ip', '-4', '-o', 'addr', 'show', 'scope', 'global']) {
        return Process::result("2: eth0    inet 10.44.0.10/24 scope global eth0\n");
    }
    if (($guest[0] ?? null) === '/usr/local/bin/receive-source.sh') {
        $sha = collect($guest)->first(
            static fn (mixed $value): bool => is_string($value) && preg_match('/\A[0-9a-f]{40}\z/', $value) === 1,
        );
        $treeHash = collect($guest)->first(
            static fn (mixed $value): bool => is_string($value) && preg_match('/\A[0-9a-f]{64}\z/', $value) === 1,
        );

        return Process::result(json_encode([
            'sha' => $sha,
            'tree_hash' => $treeHash,
        ], JSON_THROW_ON_ERROR));
    }
    if (($guest[0] ?? null) === '/usr/local/bin/verify-topology.sh') {
        return Process::result(json_encode([
            'probe' => $guest[1],
            'passed' => true,
            'identity' => $guest[3],
        ], JSON_THROW_ON_ERROR));
    }

    return Process::result();
}

/** @param list<array<array-key, mixed>> $events */
function fakePinnedWorktreeProcesses(TopologyTarget $target, array &$events): void
{
    $realProcess = new ProcessFactory;
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use (&$events, $realProcess, $target) {
        $command = $process->command;
        if (($command[0] ?? null) === 'git') {
            return $realProcess->path((string) $process->path)->run($command);
        }
        $events[] = $command;

        return pinnedWorktreeInventoryResult($command, $target) ?? pinnedWorktreeGuestResult($command);
    });
}

/** @param list<array<array-key, mixed>> $events */
function fakeOrdinaryPreparedChangeProcesses(string $repositoryRoot, TopologyTarget $target, array &$events): void
{
    $realProcess = new ProcessFactory;
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use (
        &$events,
        $realProcess,
        $repositoryRoot,
        $target,
    ) {
        $command = $process->command;
        if (($command[0] ?? null) === 'git') {
            return $realProcess->path((string) $process->path ?: $repositoryRoot)->run($command);
        }
        $events[] = $command;
        if (($command[6] ?? null) === '/usr/local/bin/converge-gateway.sh') {
            return Process::result('', 'controlled convergence failure', 1);
        }

        return pinnedWorktreeInventoryResult($command, $target) ?? pinnedWorktreeGuestResult($command);
    });
}

it('requires an exact issue and a real absolute worktree', function () {
    expect(fn () => new TopologyRequest('feature-12', __DIR__))
        ->toThrow(InvalidArgumentException::class, 'Linear issue ID');
    expect(fn () => new TopologyRequest('NCK-12', 'relative/path'))
        ->toThrow(InvalidArgumentException::class, 'absolute');

    $request = new TopologyRequest('NCK-12', dirname(__DIR__, 4));

    expect($request->target->issue)
        ->toBe('NCK-12')
        ->and($request->worktree)
        ->toBe(realpath(dirname(__DIR__, 4)));
});

it('binds proof to exact candidate and tree identities', function () {
    $proof = new ProofResult(
        str_repeat('a', 32),
        str_repeat('b', 32),
        str_repeat('c', 40),
        str_repeat('e', 40),
        str_repeat('d', 64),
        new VerificationReport(true, ['candidate.probes' => true]),
    );

    expect($proof->toArray())->toMatchArray([
        'state' => 'proved',
        'candidate_sha' => str_repeat('c', 40),
        'candidate_tree' => str_repeat('e', 40),
        'tree_hash' => str_repeat('d', 64),
    ]);
});

it('checks copied ownership before applying issue metadata', function () {
    Process::fake(function (\Illuminate\Process\PendingProcess $process) {
        if (str_contains(implode(' ', $process->command ?? []), 'list')) {
            return Process::result(json_encode([[
                'name' => 'orbit-e2e-nck-123-gateway',
                'type' => 'virtual-machine',
                'status' => 'Stopped',
                'status_code' => 102,
                'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                'devices' => ['root' => ['pool' => 'orbit-e2e']],
            ]], JSON_THROW_ON_ERROR));
        }

        return Process::result();
    });

    $host = new IncusHost(remote: 'lab', project: 'orbit', pool: 'orbit-e2e');
    $host->setMetadata('orbit-e2e-nck-123-gateway', ['user.orbit.e2e.issue' => 'NCK-123']);

    Process::assertRanInOrder([
        ['incus', '--project', 'orbit', 'list', 'lab:orbit-e2e-nck-123-gateway', '--format=json'],
        [
            'incus',
            '--project',
            'orbit',
            'config',
            'set',
            'lab:orbit-e2e-nck-123-gateway',
            'user.orbit.e2e.issue=NCK-123',
        ],
    ]);
});

it('limits proof checkout identity checks to the configured checkout roles', function () {
    expect(\App\E2E\Value\TopologyProfile::CHECKOUT_ROLES)->toBe(['gateway', 'app-dev']);
});

it('preflights every rollback target before any deletion', function () {
    $read = [];
    $mutations = [];
    $rollback = new AcquisitionRollback(
        function (string $resource) use (&$read): IncusInstance|IncusNetwork|null {
            $read[] = $resource;

            return new IncusInstance('lab', 'orbit', $resource, 'orbit-e2e', [
                'user.orbit.e2e.owner' => 'orbit-e2e',
                'user.orbit.e2e.issue' => 'NCK-123',
                'user.orbit.e2e.operation' => 'operation-1',
            ]);
        },
        function (string $resource) use (&$mutations): void {
            $mutations[] = 'stop:'.$resource;
        },
        function (string $resource) use (&$mutations): void {
            $mutations[] = 'delete:'.$resource;
        },
        function (string $resource) use (&$mutations): void {
            $mutations[] = 'network:'.$resource;
        },
    );
    $target = new TopologyTarget('NCK-123');
    $identity = static fn (string $name): array => [
        'remote' => 'lab',
        'project' => 'orbit',
        'name' => $name,
        'pool' => 'orbit-e2e',
        'metadata' => [
            'user.orbit.e2e.owner' => 'orbit-e2e',
            'user.orbit.e2e.issue' => 'NCK-123',
            'user.orbit.e2e.operation' => 'operation-1',
        ],
    ];

    $result = $rollback->cleanup(
        $target,
        ['orbit-e2e-nck-123-gateway', 'orbit-e2e-nck-123-app-dev'],
        [
            'orbit-e2e-nck-123-gateway' => $identity('orbit-e2e-nck-123-gateway'),
            'orbit-e2e-nck-123-app-dev' => ['remote' => 'lab'],
        ],
        new OperationId('operation-1'),
    );

    expect($result['orbit-e2e-nck-123-gateway'])
        ->toBe('retained_due_to_preflight_failure')
        ->and($mutations)
        ->toBeEmpty()
        ->and($read)
        ->toBe(['orbit-e2e-nck-123-gateway', 'orbit-e2e-nck-123-app-dev']);
});

it('refuses replacement ownership and re-reads before rollback mutation', function () {
    $reads = 0;
    $mutations = [];
    $rollback = new AcquisitionRollback(
        function (string $resource) use (&$reads): IncusInstance {
            $reads++;
            $metadata = $reads === 2
                ? ['user.orbit.e2e.owner' => 'replacement']
                : [
                    'user.orbit.e2e.owner' => 'orbit-e2e',
                    'user.orbit.e2e.issue' => 'NCK-123',
                    'user.orbit.e2e.operation' => 'operation-1',
                ];

            return new IncusInstance('lab', 'orbit', $resource, 'orbit-e2e', $metadata);
        },
        function (string $resource) use (&$mutations): void {
            $mutations[] = 'stop:'.$resource;
        },
        function (string $resource) use (&$mutations): void {
            $mutations[] = 'delete:'.$resource;
        },
        function (string $resource) use (&$mutations): void {
            $mutations[] = 'network:'.$resource;
        },
    );
    $target = new TopologyTarget('NCK-123');
    $identity = [
        'remote' => 'lab',
        'project' => 'orbit',
        'name' => 'orbit-e2e-nck-123-gateway',
        'pool' => 'orbit-e2e',
        'metadata' => [
            'user.orbit.e2e.owner' => 'orbit-e2e',
            'user.orbit.e2e.issue' => 'NCK-123',
            'user.orbit.e2e.operation' => 'operation-1',
        ],
    ];

    $result = $rollback->cleanup(
        $target,
        ['orbit-e2e-nck-123-gateway'],
        ['orbit-e2e-nck-123-gateway' => $identity],
        new OperationId('operation-1'),
    );

    expect($result['orbit-e2e-nck-123-gateway'])
        ->toStartWith('failed:')
        ->and($reads)
        ->toBe(2)
        ->and($mutations)
        ->toBeEmpty();
});

it('uses the acquisition rollback after a topology creation failure', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(sys_get_temp_dir().'/orbit-acquirer-state-'.bin2hex(random_bytes(8)));
    $store = new AtomicJsonStore($paths);
    $fingerprints = new PreparedStateFingerprint(new GitRepository($repositoryRoot));
    $prepared = $fingerprints->forCommit();
    $generation = new StandbyGeneration(
        preparedGenerationId($repositoryRoot, $prepared->value),
        new GitRepository($repositoryRoot)->commit(),
        [
            'gateway' => 'main-gateway',
            'app-dev' => 'main-app-dev',
            'app-prod' => 'main-app-prod',
        ],
        $prepared->value,
        str_repeat('a', 64),
        new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0'),
    );
    new StandbyManifestStore($store, $paths)->promote($generation);
    $reads = [];
    $rollback = new AcquisitionRollback(
        function (string $resource) use (&$reads): never {
            $reads[] = $resource;

            throw new RuntimeException('rollback boundary used');
        },
        static function (): void {},
        static function (): void {},
        static function (): void {},
    );
    $target = new TopologyTarget('NCK-123');
    $realProcess = new ProcessFactory;
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use ($target, $repositoryRoot, $realProcess) {
        if (($process->command[0] ?? null) === 'git') {
            $events[] = $process->command;

            return $realProcess->path($repositoryRoot)->run($process->command);
        }
        if (in_array('image', $process->command, true) && in_array('list', $process->command, true)) {
            return Process::result(preparedBaseImageJson(str_repeat('a', 64)));
        }
        if (in_array('network', $process->command, true) && in_array('list', $process->command, true)) {
            return Process::result(json_encode([[
                'name' => $target->network(),
                'config' => [
                    'user.orbit.e2e.owner' => 'orbit-e2e',
                    'user.orbit.e2e.issue' => 'NCK-123',
                ],
            ]], JSON_THROW_ON_ERROR));
        }
        if (
            $process->command === [
                'incus',
                '--project',
                'default',
                'list',
                'local:'.$target->instance('gateway'),
                '--format=json',
            ]
        ) {
            return Process::result(topologyVmJson($target->instance('gateway')));
        }
        if (in_array('list', $process->command, true) && ! in_array('snapshot', $process->command, true)) {
            return Process::result(standbyVmInventoryJson());
        }
        if (in_array('snapshot', $process->command, true) && in_array('list', $process->command, true)) {
            $instance = preg_replace('/\A[^:]+:/', '', (string) ($process->command[5] ?? ''));

            return Process::result(standbySnapshotInventoryJson($instance));
        }
        if (
            $process->command === [
                'incus',
                '--project',
                'default',
                'copy',
                'local:orbit-e2e-standby-gateway/main-gateway',
                'local:'.$target->instance('gateway'),
                '--storage',
                'default',
            ]
        ) {
            return Process::result('', 'copy failed', 1);
        }

        return Process::result();
    });
    $acquirer = taskNineAcquirer($repositoryRoot, $paths, $rollback);
    $request = new TopologyRequest('NCK-123', $repositoryRoot);

    expect(fn () => $acquirer->acquire($request))
        ->toThrow(RuntimeException::class, 'copy failed')
        ->and($reads)
        ->toBe([$target->network()]);
});

it('preflights all standby snapshots before any network or copy mutation', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(sys_get_temp_dir().'/orbit-acquirer-state-'.bin2hex(random_bytes(8)));
    $store = new AtomicJsonStore($paths);
    $fingerprints = new PreparedStateFingerprint(new GitRepository($repositoryRoot));
    $prepared = $fingerprints->forCommit();
    $generation = new StandbyGeneration(
        preparedGenerationId($repositoryRoot, $prepared->value),
        new GitRepository($repositoryRoot)->commit(),
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        $prepared->value,
        str_repeat('a', 64),
        new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0'),
    );
    new StandbyManifestStore($store, $paths)->promote($generation);
    $commands = [];
    $acquirer = taskNineAcquirer($repositoryRoot, $paths);
    $request = new TopologyRequest('NCK-123', $repositoryRoot);
    $realProcess = new ProcessFactory;

    Process::fake(function (\Illuminate\Process\PendingProcess $process) use (
        &$commands,
        $repositoryRoot,
        $realProcess,
    ) {
        $commands[] = $process->command;

        if (($process->command[0] ?? null) === 'git') {
            return $realProcess->path($repositoryRoot)->run($process->command);
        }
        if (in_array('image', $process->command, true) && in_array('list', $process->command, true)) {
            return Process::result(preparedBaseImageJson(str_repeat('a', 64)));
        }

        if (in_array('snapshot', $process->command, true) && in_array('list', $process->command, true)) {
            $instance = preg_replace('/\A[^:]+:/', '', (string) ($process->command[5] ?? ''));
            $include = $instance !== TopologyTarget::standby()->instance('app-prod');

            return Process::result(standbySnapshotInventoryJson($instance, include: $include));
        }

        if (in_array('list', $process->command, true)) {
            return Process::result(standbyVmInventoryJson());
        }

        return Process::result();
    });

    expect(fn () => $acquirer->acquire($request))
        ->toThrow(RuntimeException::class, 'snapshot identity changed');

    expect(collect($commands)->contains(
        fn (array $command): bool => in_array('network', $command, true) && in_array('create', $command, true),
    ))
        ->toBeFalse()
        ->and(collect($commands)->contains(fn (array $command): bool => in_array('copy', $command, true)))
        ->toBeFalse();
});

it('blocks acquisition when the standby is marked corrupt before Incus mutation', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(sys_get_temp_dir().'/orbit-acquirer-state-'.bin2hex(random_bytes(8)));
    $store = new AtomicJsonStore($paths);
    $prepared = new PreparedStateFingerprint(new GitRepository($repositoryRoot))->forCommit();
    new StandbyManifestStore($store, $paths)->promote(new StandbyGeneration(
        preparedGenerationId($repositoryRoot, $prepared->value),
        new GitRepository($repositoryRoot)->commit(),
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        $prepared->value,
        str_repeat('a', 64),
        new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0'),
    ));
    $store->write('standby/corrupt.json', [
        'schema' => 1,
        'evidence_id' => str_repeat('b', 32),
        'message' => 'rollback failed',
    ]);
    $commands = [];
    $realProcess = new ProcessFactory;
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use (
        &$commands,
        $repositoryRoot,
        $realProcess,
    ) {
        if (($process->command[0] ?? null) === 'git') {
            return $realProcess->path($repositoryRoot)->run($process->command);
        }

        $commands[] = $process->command;

        return Process::result();
    });

    expect(fn () => taskNineAcquirer($repositoryRoot, $paths)->acquire(new TopologyRequest('NCK-123', $repositoryRoot)))
        ->toThrow(RuntimeException::class, 'marked corrupt')
        ->and($commands)
        ->toBeEmpty();
});

it('requires the promoted generation fingerprint to match its exact main commit', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(sys_get_temp_dir().'/orbit-acquirer-state-'.bin2hex(random_bytes(8)));
    $store = new AtomicJsonStore($paths);
    $prepared = new PreparedStateFingerprint(new GitRepository($repositoryRoot))->forCommit();
    new StandbyManifestStore($store, $paths)->promote(new StandbyGeneration(
        preparedGenerationId($repositoryRoot, $prepared->value),
        new GitRepository($repositoryRoot)->commit(),
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        str_repeat('c', 64),
        str_repeat('a', 64),
        new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0'),
    ));
    $commands = [];
    $realProcess = new ProcessFactory;
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use (
        &$commands,
        $repositoryRoot,
        $realProcess,
    ) {
        if (($process->command[0] ?? null) === 'git') {
            return $realProcess->path($repositoryRoot)->run($process->command);
        }

        $commands[] = $process->command;

        return Process::result();
    });

    $acquirer = taskNineAcquirer($repositoryRoot, $paths);
    expect(fn () => $acquirer->acquire(new TopologyRequest('NCK-123', $repositoryRoot)))
        ->toThrow(RuntimeException::class, 'fingerprint is stale or corrupt')
        ->and($commands)
        ->toBeEmpty();
});

it('blocks acquisition when current main requires a newer prepared generation', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(sys_get_temp_dir().'/orbit-acquirer-state-'.bin2hex(random_bytes(8)));
    $store = new AtomicJsonStore($paths);
    $fingerprints = new PreparedStateFingerprint(new GitRepository($repositoryRoot));
    $prepared = $fingerprints->forCommit();
    $generationSha = new GitRepository($repositoryRoot)->commit();
    new StandbyManifestStore($store, $paths)->promote(new StandbyGeneration(
        preparedGenerationId($repositoryRoot, $prepared->value),
        $generationSha,
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        $prepared->value,
        str_repeat('a', 64),
        new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0'),
    ));
    file_put_contents($repositoryRoot.'/apps/e2e/resources/guest/converge-gateway.sh', "changed\n");
    foreach ([
        ['git', '-C', $repositoryRoot, 'add',    '.'],
        ['git', '-C', $repositoryRoot, 'commit', '-q', '-m',   'Prepared state changed'],
        ['git', '-C', $repositoryRoot, 'branch', '-f', 'main', 'HEAD'],
    ] as $command) {
        expect(Process::run($command)->successful())->toBeTrue();
    }
    $commands = [];
    $realProcess = new ProcessFactory;
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use (
        &$commands,
        $repositoryRoot,
        $realProcess,
    ) {
        if (($process->command[0] ?? null) === 'git') {
            return $realProcess->path($repositoryRoot)->run($process->command);
        }

        $commands[] = $process->command;

        return Process::result();
    });

    $acquirer = taskNineAcquirer($repositoryRoot, $paths);
    expect(fn () => $acquirer->acquire(new TopologyRequest('NCK-123', $repositoryRoot)))
        ->toThrow(RuntimeException::class, 'prepared state is stale')
        ->and($commands)
        ->toBeEmpty();
});

it('reuses a prepared generation when main advances with a source-only change', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(sys_get_temp_dir().'/orbit-acquirer-state-'.bin2hex(random_bytes(8)));
    $store = new AtomicJsonStore($paths);
    $fingerprints = new PreparedStateFingerprint(new GitRepository($repositoryRoot));
    $prepared = $fingerprints->forCommit();
    $generationSha = new GitRepository($repositoryRoot)->commit();
    new StandbyManifestStore($store, $paths)->promote(new StandbyGeneration(
        preparedGenerationId($repositoryRoot, $prepared->value),
        $generationSha,
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        $prepared->value,
        str_repeat('a', 64),
        new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0'),
    ));
    file_put_contents($repositoryRoot.'/README.md', "source-only\n");
    expect(Process::run(['git', '-C', $repositoryRoot, 'add', 'README.md'])->successful())->toBeTrue();
    expect(
        Process::run(['git', '-C', $repositoryRoot, 'commit', '-q', '-m', 'Source only'])->successful(),
    )->toBeTrue();
    expect(Process::run(['git', '-C', $repositoryRoot, 'branch', '-f', 'main', 'HEAD'])->successful())->toBeTrue();
    expect($prepared->value)->toBe($fingerprints->forCommit('main')->value);
    $commands = [];
    $realProcess = new ProcessFactory;
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use (
        &$commands,
        $repositoryRoot,
        $realProcess,
    ) {
        if (($process->command[0] ?? null) === 'git') {
            return $realProcess->path($repositoryRoot)->run($process->command);
        }
        $commands[] = $process->command;
        if (in_array('image', $process->command, true) && in_array('list', $process->command, true)) {
            return Process::result(preparedBaseImageJson(str_repeat('a', 64)));
        }
        if (in_array('snapshot', $process->command, true) && in_array('list', $process->command, true)) {
            $instance = preg_replace('/\A[^:]+:/', '', (string) ($process->command[5] ?? ''));

            return Process::result(standbySnapshotInventoryJson($instance));
        }
        if (in_array('list', $process->command, true)) {
            return Process::result(standbyVmInventoryJson());
        }
        if (in_array('network', $process->command, true) && in_array('create', $process->command, true)) {
            return Process::result('', 'controlled network failure', 1);
        }

        return Process::result();
    });
    expect(fn () => taskNineAcquirer($repositoryRoot, $paths)->acquire(new TopologyRequest('NCK-123', $repositoryRoot)))
        ->toThrow(RuntimeException::class, 'controlled network failure');
    expect(collect($commands)->contains(
        fn (array $command): bool => in_array('network', $command, true) && in_array('create', $command, true),
    ))->toBeTrue();
});

it('blocks acquisition when the promoted base image no longer matches its alias', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(sys_get_temp_dir().'/orbit-acquirer-state-'.bin2hex(random_bytes(8)));
    $store = new AtomicJsonStore($paths);
    $prepared = new PreparedStateFingerprint(new GitRepository($repositoryRoot))->forCommit();
    new StandbyManifestStore($store, $paths)->promote(new StandbyGeneration(
        preparedGenerationId($repositoryRoot, $prepared->value),
        new GitRepository($repositoryRoot)->commit(),
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        $prepared->value,
        str_repeat('a', 64),
        new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0'),
    ));
    $commands = [];
    $realProcess = new ProcessFactory;
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use (
        &$commands,
        $repositoryRoot,
        $realProcess,
    ) {
        if (($process->command[0] ?? null) === 'git') {
            return $realProcess->path($repositoryRoot)->run($process->command);
        }

        $commands[] = $process->command;
        if (in_array('image', $process->command, true) && in_array('list', $process->command, true)) {
            return Process::result(preparedBaseImageJson(str_repeat('b', 64)));
        }

        return Process::result();
    });

    $acquirer = taskNineAcquirer($repositoryRoot, $paths);
    expect(fn () => $acquirer->acquire(new TopologyRequest('NCK-123', $repositoryRoot)))
        ->toThrow(RuntimeException::class, 'base image fingerprint is stale')
        ->and(collect($commands)->contains(
            fn (array $command): bool => in_array('network', $command, true) && in_array('create', $command, true),
        ))
        ->toBeFalse();
});

it('sets unique MACs before startup and preserves identity refresh failure through rollback', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(sys_get_temp_dir().'/orbit-acquirer-state-'.bin2hex(random_bytes(8)));
    $store = new AtomicJsonStore($paths);
    $fingerprints = new PreparedStateFingerprint(new GitRepository($repositoryRoot));
    $prepared = $fingerprints->forCommit();
    $generation = new StandbyGeneration(
        preparedGenerationId($repositoryRoot, $prepared->value),
        new GitRepository($repositoryRoot)->commit(),
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        $prepared->value,
        str_repeat('a', 64),
        new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0'),
    );
    new StandbyManifestStore($store, $paths)->promote($generation);
    $target = new TopologyTarget('NCK-123');
    $operationId = null;
    $events = [];
    $mutations = [];
    $rollback = identityRefreshRollback($target, $operationId, $mutations);
    fakeIdentityRefreshFailure($repositoryRoot, $target, $events, $operationId);
    $acquirer = taskNineAcquirer(
        $repositoryRoot,
        $paths,
        $rollback,
        new IncusHost(guestReadinessTimeoutSeconds: 1),
    );

    expect(fn () => $acquirer->acquire(new TopologyRequest('NCK-123', $repositoryRoot)))
        ->toThrow(RuntimeException::class, 'Failed to regenerate network identity')
        ->and(array_slice($events, 0, 6))
        ->toBe(['mac', 'start', 'mac', 'start', 'mac', 'start'])
        ->and(array_slice($events, 6, 3))
        ->toBe(['readiness', 'readiness', 'readiness'])
        ->and($events[9])
        ->toBe('identity')
        ->and($operationId)
        ->toMatch('/\A[0-9a-f]{32}\z/')
        ->and($mutations)
        ->not->toBeEmpty();
});

it('rejects unrelated clean repositories before lock state or Incus access', function () {
    $repositoryRoot = dirname(__DIR__, 5);
    $unrelated = sys_get_temp_dir().'/orbit-unrelated-'.bin2hex(random_bytes(8));
    mkdir($unrelated, 0o700);
    foreach ([
        ['git', 'init', '-q', '-b', 'feature/NCK-12', $unrelated],
        ['git', '-C', $unrelated, 'config', 'user.email', 'developer@example.com'],
        ['git', '-C', $unrelated, 'config', 'user.name', 'Orbit Developer'],
        ['git', '-C', $unrelated, 'commit', '--allow-empty', '-q', '-m', 'Initial'],
    ] as $command) {
        expect(Process::run($command)->successful())->toBeTrue();
    }
    $paths = new StatePaths(sys_get_temp_dir().'/orbit-acquirer-state-'.bin2hex(random_bytes(8)));
    $acquirer = taskNineAcquirer($repositoryRoot, $paths);

    expect(fn () => $acquirer->sync(new TopologyRequest('NCK-12', $unrelated)))
        ->toThrow(InvalidArgumentException::class, 'repository identity')
        ->and(is_dir($paths->root().'/locks'))
        ->toBeFalse();
    expect(fn () => $acquirer->prove(new TopologyRequest('NCK-12', $unrelated), str_repeat('a', 40)))
        ->toThrow(InvalidArgumentException::class, 'repository identity')
        ->and(is_dir($paths->root().'/locks'))
        ->toBeFalse();
});

it('rejects a wrong issue branch before creating lifecycle state', function () {
    $repositoryRoot = dirname(__DIR__, 5);
    $inventory = Process::run(['git', '-C', $repositoryRoot, 'worktree', 'list', '--porcelain']);
    preg_match('/\Aworktree ([^\r\n]+)/', $inventory->output(), $matches);
    $branchWorktree = $matches[1] ?? '';
    expect($inventory->successful())
        ->toBeTrue()
        ->and($branchWorktree)
        ->not->toBe('');
    $paths = new StatePaths(sys_get_temp_dir().'/orbit-acquirer-state-'.bin2hex(random_bytes(8)));
    $acquirer = taskNineAcquirer($branchWorktree, $paths);

    expect(fn () => $acquirer->sync(new TopologyRequest('NCK-999999', $branchWorktree)))
        ->toThrow(InvalidArgumentException::class, 'branch does not match')
        ->and(is_dir($paths->root().'/locks'))
        ->toBeFalse();
});

it('sync rejects a feature HEAD cold epoch change before source sync or Incus', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(sys_get_temp_dir().'/orbit-acquirer-state-'.bin2hex(random_bytes(8)));
    featureTopologyFixture($repositoryRoot, $paths);
    $manifest = json_decode(
        (string) file_get_contents($repositoryRoot.'/apps/e2e/resources/prepared-state.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $manifest['cold_epoch'] = 'ubuntu-26.04-amd64-v2';
    file_put_contents($repositoryRoot.'/apps/e2e/resources/prepared-state.json', json_encode(
        $manifest,
        JSON_THROW_ON_ERROR,
    ));
    Process::run(['git', '-C', $repositoryRoot, 'add', '.']);
    Process::run(['git', '-C', $repositoryRoot, 'commit', '-q', '-m', 'Change cold epoch']);
    $commands = [];
    $realProcess = new ProcessFactory;
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use (
        &$commands,
        $repositoryRoot,
        $realProcess,
    ) {
        if (($process->command[0] ?? null) === 'git') {
            return $realProcess->path($repositoryRoot)->run($process->command);
        }
        $commands[] = $process->command;

        return Process::result();
    });

    expect(fn () => taskNineAcquirer($repositoryRoot, $paths)->sync(new TopologyRequest(
        'NCK-123',
        $repositoryRoot,
    )))
        ->toThrow(InvalidArgumentException::class, 'cold base contract')
        ->and($commands)
        ->toBeEmpty();
});

it('sync rejects a feature HEAD base image alias change before source sync or Incus', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(sys_get_temp_dir().'/orbit-acquirer-state-'.bin2hex(random_bytes(8)));
    featureTopologyFixture($repositoryRoot, $paths);
    $manifest = json_decode(
        (string) file_get_contents($repositoryRoot.'/apps/e2e/resources/prepared-state.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $manifest['base_image_alias'] = 'orbit-base-ubuntu-26.04-other';
    file_put_contents($repositoryRoot.'/apps/e2e/resources/prepared-state.json', json_encode(
        $manifest,
        JSON_THROW_ON_ERROR,
    ));
    Process::run(['git', '-C', $repositoryRoot, 'add', '.']);
    Process::run(['git', '-C', $repositoryRoot, 'commit', '-q', '-m', 'Change base alias']);
    $commands = [];
    $realProcess = new ProcessFactory;
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use (
        &$commands,
        $repositoryRoot,
        $realProcess,
    ) {
        if (($process->command[0] ?? null) === 'git') {
            return $realProcess->path($repositoryRoot)->run($process->command);
        }
        $commands[] = $process->command;

        return Process::result();
    });

    expect(fn () => taskNineAcquirer($repositoryRoot, $paths)->sync(new TopologyRequest(
        'NCK-123',
        $repositoryRoot,
    )))
        ->toThrow(InvalidArgumentException::class, 'cold base contract')
        ->and($commands)
        ->toBeEmpty();
});

it('allows an ordinary prepared-state change through the cold-base gate', function () {
    $repositoryRoot = preparedTopologyRepository();
    $featureWorktree = $repositoryRoot.'-worktree';
    $paths = new StatePaths(sys_get_temp_dir().'/orbit-acquirer-state-'.bin2hex(random_bytes(8)));
    featureTopologyFixture($repositoryRoot, $paths);
    file_put_contents($repositoryRoot.'/apps/e2e/resources/guest/converge-gateway.sh', "ordinary change\n");
    Process::run(['git', '-C', $repositoryRoot, 'add', '.']);
    Process::run(['git', '-C', $repositoryRoot, 'commit', '-q', '-m', 'Change prepared script']);
    Process::run([
        'git',
        '-C',
        $repositoryRoot,
        'worktree',
        'add',
        '-q',
        '-b',
        'NCK-123-prepared',
        $featureWorktree,
        'HEAD',
    ]);
    $events = [];
    fakeOrdinaryPreparedChangeProcesses($repositoryRoot, new TopologyTarget('NCK-123'), $events);

    try {
        expect(fn () => taskNineAcquirer($repositoryRoot, $paths)->sync(new TopologyRequest(
            'NCK-123',
            $featureWorktree,
        )))
            ->toThrow(RuntimeException::class)
            ->and(collect($events)->contains(fn (array $command): bool => str_contains(
                implode(' ', $command),
                'receive-source.sh',
            )))
            ->toBeTrue()
            ->and(collect($events)->contains(fn (array $command): bool => str_contains(
                implode(' ', $command),
                'converge-gateway.sh',
            )))
            ->toBeTrue();
    } finally {
        Process::run(['git', '-C', $repositoryRoot, 'worktree', 'remove', '--force', $featureWorktree]);
    }
});

it('rejects a valid promoted fingerprint with a wrong generation ID before Incus', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(sys_get_temp_dir().'/orbit-acquirer-state-'.bin2hex(random_bytes(8)));
    $store = new AtomicJsonStore($paths);
    $fingerprint = new PreparedStateFingerprint(new GitRepository($repositoryRoot))->forCommit();
    new StandbyManifestStore($store, $paths)->promote(new StandbyGeneration(
        'wrong-generation-id',
        new GitRepository($repositoryRoot)->commit(),
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        $fingerprint->value,
        str_repeat('a', 64),
        new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0'),
    ));
    $commands = [];
    $realProcess = new ProcessFactory;
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use (
        &$commands,
        $repositoryRoot,
        $realProcess,
    ) {
        if (($process->command[0] ?? null) === 'git') {
            return $realProcess->path($repositoryRoot)->run($process->command);
        }
        $commands[] = $process->command;

        return Process::result();
    });
    expect(fn () => taskNineAcquirer($repositoryRoot, $paths)->acquire(new TopologyRequest('NCK-123', $repositoryRoot)))
        ->toThrow(RuntimeException::class, 'fingerprint is stale or corrupt')
        ->and($commands)
        ->toBeEmpty();
});

it('keeps the promoted Laravel pin when feature worktrees change their manifest pin', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(sys_get_temp_dir().'/orbit-acquirer-state-'.bin2hex(random_bytes(8)));
    $worktreeA = pinnedFeatureWorktree($repositoryRoot, 'a', 'v14.1.2', str_repeat('c', 40));
    $worktreeB = pinnedFeatureWorktree($repositoryRoot, 'b', 'v15.2.3', str_repeat('d', 40));
    $store = new AtomicJsonStore($paths);
    $prepared = new PreparedStateFingerprint(new GitRepository($repositoryRoot))->forCommit();
    $mainSha = new GitRepository($repositoryRoot)->commit();
    $promotedLaravel = new LaravelRelease(
        'v13.10.1',
        '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0',
    );
    new StandbyManifestStore($store, $paths)->promote(new StandbyGeneration(
        substr($mainSha, 0, 12).'-'.substr($prepared->value, 0, 12),
        $mainSha,
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        $prepared->value,
        str_repeat('b', 64),
        $promotedLaravel,
    ));
    $target = new TopologyTarget('NCK-123');
    $events = [];
    fakePinnedWorktreeProcesses($target, $events);

    $acquirer = taskNineAcquirer($repositoryRoot, $paths);
    $acquired = $acquirer->acquire(new TopologyRequest('NCK-123', $worktreeA));
    $acquireEvents = $events;
    $events = [];
    $synced = $acquirer->sync(new TopologyRequest('NCK-123', $worktreeB));

    expect($acquired->source->hostSha)
        ->toBe(new GitRepository($worktreeA)->commit())
        ->and(collect($acquireEvents)->contains(fn (array $command): bool => in_array(
            $promotedLaravel->commit,
            $command,
            true,
        )))
        ->toBeTrue()
        ->and(collect($acquireEvents)->contains(fn (array $command): bool => in_array(
            str_repeat('c', 40),
            $command,
            true,
        )))
        ->toBeFalse()
        ->and($synced->source->hostSha)
        ->toBe(new GitRepository($worktreeB)->commit())
        ->and(collect($events)->contains(fn (array $command): bool => in_array(
            $promotedLaravel->commit,
            $command,
            true,
        )))
        ->toBeTrue()
        ->and(collect($events)->contains(fn (array $command): bool => in_array(
            str_repeat('d', 40),
            $command,
            true,
        )))
        ->toBeFalse();
});
