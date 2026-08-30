<?php

declare(strict_types=1);

use App\E2E\AcquisitionRollback;
use App\E2E\HostCapacity;
use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\OrphanNetworkSweep;
use App\E2E\ReleaseReceiptStore;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\OperationJournal;
use App\E2E\State\SecretRedactor;
use App\E2E\State\StatePaths;
use App\E2E\TopologyManifestStore;
use App\E2E\TopologyReleaser;
use App\E2E\Value\AttemptId;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\OperationId;
use App\E2E\Value\ReleaseResult;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyTarget;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
    Process::preventStrayProcesses();
});

const RELEASE_MOUNT_SOURCE = '/srv/worktrees/nck-12';

function releaseAttempt(string $character = 'a'): AttemptId
{
    return new AttemptId(str_repeat($character, 32));
}

function releaseTopologyPath(string $issue = 'NCK-12', string $character = 'a'): string
{
    return 'topologies/'.$issue.'/'.releaseAttempt($character)->value.'.json';
}

function releaseReceiptPath(string $issue = 'NCK-12', string $character = 'a'): string
{
    return 'evidence/releases/'.$issue.'/'.releaseAttempt($character)->value.'.json';
}

function releasePendingPath(string $issue = 'NCK-12', string $character = 'a'): string
{
    return 'release-pending/'.$issue.'/'.releaseAttempt($character)->value.'.json';
}

/** @return array<string, array{device:string,source:string,path:string}> */
function releaseMounts(): array
{
    $mounts = [];
    foreach (TopologyProfile::CHECKOUT_ROLES as $role) {
        $mounts[$role] = ['device' => 'orbit-source', 'source' => RELEASE_MOUNT_SOURCE, 'path' => '/home/orbit/orbit'];
    }

    return $mounts;
}

function readyReleaseState(AtomicJsonStore $store, string $issue = 'NCK-12', string $character = 'a'): void
{
    $target = featureTarget($issue, $character);
    $store->write('leases/'.$issue.'.json', [
        'schema' => 2,
        'issue' => $issue,
        'attempt' => releaseAttempt($character)->value,
        'state' => 'ready',
        'operation_id' => str_repeat('a', 32),
    ]);
    $store->write(releaseTopologyPath($issue, $character), [
        'schema' => 3,
        'issue' => $issue,
        'attempt_id' => releaseAttempt($character)->value,
        'purpose' => 'discovery',
        'profile' => TopologyProfile::NAME,
        'generation' => [
            'schema' => 4,
            'id' => 'generation-1',
            'main_sha' => str_repeat('a', 40),
            'snapshots' => [
                'gateway' => 'main-gateway',
                'app-dev' => 'main-app-dev',
                'app-prod' => 'main-app-prod',
            ],
            'prepared_fingerprint' => str_repeat('a', 64),
            'base_image_fingerprint' => str_repeat('b', 64),
            'structural_fingerprint' => str_repeat('e', 64),
            'prepared_schema' => 1,
            'cold_epoch' => 'ubuntu-26.04-amd64-v1',
            'base_image_alias' => 'orbit-base-ubuntu-26.04-runtime',
            'topology' => [
                'profile' => TopologyProfile::NAME,
                'roles' => TopologyProfile::ROLES,
                'checkout_roles' => TopologyProfile::CHECKOUT_ROLES,
            ],
            'laravel_pin' => ['tag' => 'v1.0.0', 'commit' => str_repeat('c', 40)],
            'previous_generation_id' => null,
        ],
        'network' => $target->network(),
        'instances' => array_combine(TopologyProfile::ROLES, array_map(
            $target->instance(...),
            TopologyProfile::ROLES,
        )),
        'mounts' => releaseMounts(),
        'source' => [
            'host_sha' => str_repeat('d', 40),
            'guest_sha' => str_repeat('d', 40),
            'dirty' => false,
            'tree_hash' => null,
            'overlay_paths' => [],
            'operation_id' => null,
            'mounted' => true,
            'git_pointer_sha256' => str_repeat('f', 64),
        ],
        'verification' => [
            'passed' => true,
            'probes' => ['ready' => verificationProbeFixture(probe: 'ready')],
        ],
    ]);
    $store->write('topologies/'.$issue.'/active.json', [
        'schema' => 2,
        'issue' => $issue,
        'attempt' => releaseAttempt($character)->value,
    ]);
}

/** A ready proof attempt: bundled candidate source, no mount device on any role. */
function readyProofReleaseState(AtomicJsonStore $store, string $issue = 'NCK-12', string $character = 'a'): void
{
    readyReleaseState($store, $issue, $character);
    $record = $store->read(releaseTopologyPath($issue, $character)) ?? [];
    $record['purpose'] = 'proof';
    $record['mounts'] = [];
    $record['source'] = [
        ...$record['source'],
        'operation_id' => str_repeat('a', 32),
        'mounted' => false,
        'git_pointer_sha256' => null,
    ];
    $store->write(releaseTopologyPath($issue, $character), $record);
}

/** @param array{operation?:string,evidence?:string,released?:list<string>,absent?:list<string>,issue?:string,character?:string} $overrides */
function releaseReceipt(array $overrides = []): ReleaseResult
{
    $receipt = $overrides
    + [
        'operation' => 'a',
        'evidence' => 'b',
        'released' => ['deleted:old'],
        'absent' => [],
        'issue' => 'NCK-12',
        'character' => 'a',
    ];

    return new ReleaseResult(
        str_repeat($receipt['operation'], 32),
        str_repeat($receipt['evidence'], 32),
        $receipt['issue'],
        releaseAttempt($receipt['character']),
        AttemptPurpose::Discovery,
        $receipt['released'],
        $receipt['absent'],
        ['verified:old'],
        '2026-08-29T10:00:00Z',
    );
}

function completedReleaseState(AtomicJsonStore $store, StatePaths $paths): void
{
    readyReleaseState($store);
    new ReleaseReceiptStore($store, $paths)->write(releaseReceipt());
}

/** @param array<array-key, mixed> $value */
function releaseStateDigest(array $value): string
{
    return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
}

function releaser(
    AtomicJsonStore $store,
    StatePaths $paths,
    ?HostCapacity $capacity = null,
    ?AcquisitionRollback $rollback = null,
    string $operation = 'a',
): TopologyReleaser {
    $host = new IncusHost;

    return new TopologyReleaser(
        $host,
        new IncusNetworkLifecycle($host),
        new TopologyManifestStore($store, $paths),
        $store,
        $paths,
        new OperationId(str_repeat($operation, 32)),
        new ReleaseReceiptStore($store, $paths),
        $capacity,
        $rollback,
    );
}

/** @return array<string, mixed> */
function releaseInstanceJson(
    TopologyTarget $target,
    string $role,
    ?array $mount = null,
    ?string $mac = null,
    string $status = 'Running',
): array {
    $devices = [
        'root' => ['pool' => 'default'],
        'eth0' => ['network' => $target->network(), 'hwaddr' => $mac ?? $target->mac($role)],
    ];
    $mount ??= in_array($role, TopologyProfile::CHECKOUT_ROLES, true)
        ? ['source' => RELEASE_MOUNT_SOURCE, 'path' => '/home/orbit/orbit']
        : [];
    if ($mount !== []) {
        $devices['orbit-source'] = ['type' => 'disk', ...$mount];
    }

    return [
        'name' => $target->instance($role),
        'type' => 'virtual-machine',
        'status' => $status,
        'status_code' => $status === 'Running' ? 103 : 102,
        'config' => [
            'user.orbit.e2e.owner' => 'orbit-e2e',
            'user.orbit.e2e.issue' => $target->issue,
            'user.orbit.e2e.attempt' => $target->requireAttempt()->value,
            'user.orbit.e2e.generation' => 'generation-1',
            'user.orbit.e2e.operation' => str_repeat('a', 32),
        ],
        'devices' => $devices,
    ];
}

/**
 * A live Incus fake: every exact instance and the network exist until deleted.
 *
 * @param array<string, array<string, mixed>> $instances instance name → resource JSON
 * @param list<list<string>> $commands
 * @mago-expect lint:cyclomatic-complexity The fake models each exact cleanup branch.
 */
/** @param list<string> $orphans Unused harness networks listed next to the exact network; a delete removes them. */
function fakeReleaseIncus(
    TopologyTarget $target,
    array &$instances,
    bool &$networkExists,
    array &$commands,
    array &$orphans = [],
): void {
    /** @mago-expect lint:cyclomatic-complexity The fake mirrors every Incus command a release touches. */
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use (
        &$instances,
        &$networkExists,
        &$commands,
        &$orphans,
        $target,
    ) {
        $command = $process->command;
        $commands[] = $command;
        if (
            ($command[0] ?? null) === 'python3'
            && str_ends_with((string) ($command[1] ?? ''), '/resources/host/reconcile-firewall.py')
        ) {
            $input = json_decode((string) $process->input, true, 8, JSON_THROW_ON_ERROR);
            $network = is_array($input) ? $input['network'] ?? '' : '';
            if (! is_string($network) || preg_match('/\Aoe-[a-z0-9](?:[a-z0-9-]{0,10}[a-z0-9])?\z/D', $network) !== 1) {
                return Process::result('', 'invalid network', 2);
            }

            return Process::result('{"changed":true}');
        }
        if (array_slice($command, 0, 5) === ['sudo', '-n', 'iptables', '-w', '5']) {
            return in_array('-C', $command, true) ? Process::result('', '', 1) : Process::result();
        }
        if (($command[3] ?? null) === 'network' && ($command[4] ?? null) === 'list') {
            $listing = [
                ['name' => 'oe-standby', 'used_by' => ['/1.0/instances/orbit-e2e-standby-gateway']],
                ['name' => 'control-unused', 'used_by' => []],
                ...array_map(static fn (string $name): array => ['name' => $name, 'used_by' => []], $orphans),
            ];
            if ($networkExists) {
                $listing[] = [
                    'name' => $target->network(),
                    'used_by' => [],
                    'config' => [
                        'user.orbit.e2e.owner' => 'orbit-e2e',
                        'user.orbit.e2e.issue' => $target->issue,
                        'user.orbit.e2e.attempt' => $target->requireAttempt()->value,
                        'user.orbit.e2e.operation' => str_repeat('a', 32),
                    ],
                ];
            }

            return Process::result(json_encode($listing, JSON_THROW_ON_ERROR));
        }
        if (($command[3] ?? null) === 'network' && ($command[4] ?? null) === 'delete') {
            $name = preg_replace('/\A[^:]+:/', '', (string) ($command[5] ?? ''));
            if (str_starts_with((string) $name, 'oe-stuck')) {
                return Process::result('', 'Error: network in use', 1);
            }
            if ($name === $target->network()) {
                $networkExists = false;
            } else {
                $orphans = array_values(array_diff($orphans, [$name]));
            }

            return Process::result();
        }
        if (($command[3] ?? null) === 'list') {
            $name = preg_replace('/\A[^:]+:/', '', (string) ($command[4] ?? ''));
            if ($name === '') {
                return Process::result(json_encode(array_values($instances), JSON_THROW_ON_ERROR));
            }

            return Process::result(json_encode(
                isset($instances[$name]) ? [$instances[$name]] : [],
                JSON_THROW_ON_ERROR,
            ));
        }
        if (($command[3] ?? null) === 'delete') {
            unset($instances[preg_replace('/\A[^:]+:/', '', (string) ($command[4] ?? ''))]);

            return Process::result();
        }

        return Process::result();
    });
}

/** @mago-expect lint:cyclomatic-complexity,kan-defect The release scenarios keep exact cleanup behavior visible. */
describe('topology release', function () {
    it('removes an abandoned acquisition manifest after exact cleanup', function () {
        Process::fake(['*' => Process::result('[]')]);
        $paths = new StatePaths(temporaryPath('orbit-release-', 8));
        $store = new AtomicJsonStore($paths);
        readyReleaseState($store);
        $store->write('leases/NCK-12.json', [
            'schema' => 2,
            'issue' => 'NCK-12',
            'attempt' => releaseAttempt()->value,
            'state' => 'acquiring',
            'operation_id' => str_repeat('a', 32),
            'expires_at' => '2020-01-01T00:00:00+00:00',
            'pid' => 999999,
            'process_start_identity' => 'dead-test-owner',
            'acquired_at' => '2020-01-01T00:00:00+00:00',
        ]);
        $target = featureTarget('NCK-12');
        $rollback = new AcquisitionRollback(
            static fn (array $resources): array => array_fill_keys($resources, null),
            static function (array $resources): void {},
            static function (array $resources): void {},
            static function (string $resource): void {},
        );

        $result = releaser($store, $paths, rollback: $rollback, operation: 'b')->release(
            $target->issue,
            releaseAttempt(),
        );

        expect($result->alreadyAbsent)
            ->toHaveCount(count(TopologyProfile::ROLES) + 1)
            ->and($result->purpose)
            ->toBe(AttemptPurpose::Discovery)
            ->and($result->verifiedAbsent)
            ->toBe([...array_map($target->instance(...), TopologyProfile::ROLES), $target->network()])
            ->and($store->read(releaseTopologyPath()))
            ->toBeNull()
            ->and($store->read('topologies/NCK-12/active.json'))
            ->toBeNull()
            ->and($store->read('leases/NCK-12.json'))
            ->toBeNull()
            ->and($store->read(releaseReceiptPath()))
            ->toBe($result->toArray());
    });

    it('refuses an abandoned acquisition whose recorded VM carries another source mount', function () {
        $paths = new StatePaths(temporaryPath('orbit-release-', 8));
        $store = new AtomicJsonStore($paths);
        readyReleaseState($store);
        $store->write('leases/NCK-12.json', [
            'schema' => 2,
            'issue' => 'NCK-12',
            'attempt' => releaseAttempt()->value,
            'state' => 'acquiring',
            'operation_id' => str_repeat('a', 32),
            'expires_at' => '2020-01-01T00:00:00+00:00',
            'pid' => 999999,
            'process_start_identity' => 'dead-test-owner',
            'acquired_at' => '2020-01-01T00:00:00+00:00',
        ]);
        $target = featureTarget('NCK-12');
        $instances = [
            $target->instance('gateway') => releaseInstanceJson($target, 'gateway', [
                'source' => '/srv/worktrees/other',
                'path' => '/home/orbit/orbit',
            ]),
        ];
        $networkExists = true;
        $commands = [];
        fakeReleaseIncus($target, $instances, $networkExists, $commands);

        expect(fn () => releaser($store, $paths, operation: 'b')->release('NCK-12', releaseAttempt()))
            ->toThrow(RuntimeException::class, 'source mount does not match the topology manifest')
            ->and(collect($commands)->contains(
                static fn (array $command): bool => (
                    in_array('delete', $command, true) || in_array('stop', $command, true)
                ),
            ))
            ->toBeFalse()
            ->and($instances)
            ->toHaveCount(1)
            ->and($store->read(releaseTopologyPath()))
            ->not
            ->toBeNull()
            ->and($store->read(releaseReceiptPath()))
            ->toBeNull();
    });

    it('refuses retained evidence while active artifacts exist', function () {
        $paths = new StatePaths(temporaryPath('orbit-release-', 8));
        $store = new AtomicJsonStore($paths);
        completedReleaseState($store, $paths);
        Process::preventStrayProcesses();

        expect(fn () => releaser($store, $paths)->release('NCK-12', releaseAttempt()))
            ->toThrow(RuntimeException::class, 'active topology state');
        expect($store->read('leases/NCK-12.json'))
            ->not->toBeNull()->and($store->read(releaseTopologyPath()))
            ->not->toBeNull();
        Process::assertNothingRan();
    });

    it('preserves a retained lease when the manifest is absent but lease is current', function () {
        $paths = new StatePaths(temporaryPath('orbit-release-', 8));
        $store = new AtomicJsonStore($paths);
        completedReleaseState($store, $paths);
        unlink($paths->root().'/'.releaseTopologyPath());
        unlink($paths->root().'/topologies/NCK-12/active.json');
        Process::preventStrayProcesses();

        expect(fn () => releaser($store, $paths)->release('NCK-12', releaseAttempt()))
            ->toThrow(RuntimeException::class, 'active topology state');
        expect($store->read('leases/NCK-12.json'))->not->toBeNull();
        Process::assertNothingRan();
    });

    it('refuses retained evidence when the manifest is malformed', function () {
        $paths = new StatePaths(temporaryPath('orbit-release-', 8));
        $store = new AtomicJsonStore($paths);
        completedReleaseState($store, $paths);
        file_put_contents($paths->root().'/'.releaseTopologyPath(), '{malformed');
        Process::preventStrayProcesses();

        expect(fn () => releaser($store, $paths)->release('NCK-12', releaseAttempt()))
            ->toThrow(RuntimeException::class, 'active topology state');
        expect($store->read('leases/NCK-12.json'))
            ->not
            ->toBeNull()
            ->and(is_link($paths->root().'/'.releaseTopologyPath()))
            ->toBeFalse()
            ->and(file_exists($paths->root().'/'.releaseTopologyPath()))
            ->toBeTrue();
        Process::assertNothingRan();
    });

    it('keeps evidence identity across a local state failure boundary', function () {
        $paths = new StatePaths(temporaryPath('orbit-release-', 8));
        $store = new AtomicJsonStore($paths);
        completedReleaseState($store, $paths);
        unlink($paths->root().'/leases/NCK-12.json');
        symlink('/tmp', $paths->root().'/leases/NCK-12.json');
        Process::preventStrayProcesses();

        expect(fn () => releaser($store, $paths)->release('NCK-12', releaseAttempt()))
            ->toThrow(Exception::class);
        Process::assertNothingRan();
        expect($store->read(releaseReceiptPath())['evidence_id'] ?? null)->toBe(str_repeat('b', 32));
        unlink($paths->root().'/leases/NCK-12.json');
        expect(fn () => releaser($store, $paths)->release('NCK-12', releaseAttempt()))
            ->toThrow(RuntimeException::class, 'active topology state');
        expect($store->read(releaseTopologyPath()))->not->toBeNull();
        Process::assertNothingRan();
    });

    it('releases the exact attempt, its mount devices, and verifies absence', function () {
        $paths = new StatePaths(temporaryPath('orbit-release-complete-', 8));
        $store = new AtomicJsonStore($paths);
        $target = featureTarget('NCK-123');
        readyReleaseState($store, $target->issue);
        $capacity = new HostCapacity($store, $paths, new OperationId(str_repeat('f', 32)), 12);
        $capacity->reserve('NCK-123', releaseAttempt(), new OperationId(str_repeat('a', 32)));
        $instances = [];
        foreach (TopologyProfile::ROLES as $role) {
            $instances[$target->instance($role)] = releaseInstanceJson($target, $role);
        }
        $networkExists = true;
        $commands = [];
        fakeReleaseIncus($target, $instances, $networkExists, $commands);

        $before = ReleaseResult::now();
        $result = releaser($store, $paths, $capacity)->release('NCK-123', releaseAttempt());
        $receipt = $store->read(releaseReceiptPath('NCK-123'));

        expect($result->operationId)
            ->toBe(str_repeat('a', 32))
            ->and($result->evidenceId)
            ->not
            ->toBe($result->operationId)
            ->and($result->issue)
            ->toBe('NCK-123')
            ->and($result->attempt->value)
            ->toBe(releaseAttempt()->value)
            ->and($result->purpose)
            ->toBe(AttemptPurpose::Discovery)
            ->and($result->released)
            ->toBe([
                'stopped:'.$target->instance('gateway'),
                'stopped:'.$target->instance('app-dev'),
                'stopped:'.$target->instance('app-prod'),
                'deleted:'.$target->instance('app-prod'),
                'deleted:'.$target->instance('app-dev'),
                'device:'.$target->instance('app-dev').':orbit-source',
                'deleted:'.$target->instance('gateway'),
                'device:'.$target->instance('gateway').':orbit-source',
                'deleted:'.$target->network(),
            ])
            ->and($result->alreadyAbsent)
            ->toBe([])
            ->and($result->verifiedAbsent)
            ->toBe([...array_map($target->instance(...), TopologyProfile::ROLES), $target->network()])
            ->and(strcmp($result->releasedAt, $before) >= 0)
            ->toBeTrue()
            ->and($receipt)
            ->toBe($result->toArray())
            ->and(array_keys($receipt ?? []))
            ->toBe(ReleaseResult::KEYS)
            ->and($instances)
            ->toBe([])
            ->and($networkExists)
            ->toBeFalse()
            ->and($store->read('leases/NCK-123.json'))
            ->toBeNull()
            ->and($store->read('topologies/NCK-123/active.json'))
            ->toBeNull()
            ->and($store->read(releaseTopologyPath('NCK-123')))
            ->toBeNull()
            ->and($store->read(releasePendingPath('NCK-123')))
            ->toBeNull()
            ->and($store->read('capacity/incus.json'))
            ->toBe(['schema' => 2, 'reservations' => []]);
        $stops = array_values(array_filter(
            $commands,
            static fn (array $command): bool => ($command[3] ?? null) === 'stop',
        ));
        expect($stops)
            ->toHaveCount(3)
            ->and(collect($stops)->every(
                static fn (array $command): bool => in_array('--force', $command, true),
            ))
            ->toBeTrue()
            ->and(collect($commands)->contains(
                static fn (array $command): bool => in_array(RELEASE_MOUNT_SOURCE, $command, true),
            ))
            ->toBeFalse();
    });

    it('ends every release with the orphan network sweep and records each deletion', function () {
        $paths = new StatePaths(temporaryPath('orbit-release-sweep-', 8));
        $store = new AtomicJsonStore($paths);
        $target = featureTarget('NCK-123');
        readyReleaseState($store, $target->issue);
        $instances = [];
        foreach (TopologyProfile::ROLES as $role) {
            $instances[$target->instance($role)] = releaseInstanceJson($target, $role);
        }
        $orphans = ['oe-orphan1', 'orbit-e2e-n-legacy'];
        $networkExists = true;
        $commands = [];
        fakeReleaseIncus($target, $instances, $networkExists, $commands, $orphans);
        $host = new IncusHost;
        $sweep = new OrphanNetworkSweep(
            $host,
            new IncusNetworkLifecycle($host),
            $store,
            $paths,
            new OperationJournal($paths, new SecretRedactor),
            new OperationId(str_repeat('a', 32)),
        );
        $build = fn (): TopologyReleaser => new TopologyReleaser(
            $host,
            new IncusNetworkLifecycle($host),
            new TopologyManifestStore($store, $paths),
            $store,
            $paths,
            new OperationId(str_repeat('a', 32)),
            new ReleaseReceiptStore($store, $paths),
            sweep: $sweep,
        );

        $result = $build()->release('NCK-123', releaseAttempt());
        $receipt = $store->read(releaseReceiptPath('NCK-123'));

        expect($result->networksReaped)
            ->toBe(['oe-orphan1', 'orbit-e2e-n-legacy'])
            ->and($result->released)
            ->toContain('deleted:'.$target->network())
            ->and($receipt['networks_reaped'] ?? null)
            ->toBe(['oe-orphan1', 'orbit-e2e-n-legacy'])
            ->and($orphans)
            ->toBe([])
            ->and($networkExists)
            ->toBeFalse();

        // A repeated release sweeps again: nothing left reports 0 and keeps the receipt.
        $replay = $build()->release('NCK-123', releaseAttempt());
        expect($replay->networksReaped)
            ->toBe([])
            ->and($store->read(releaseReceiptPath('NCK-123')))
            ->toBe($receipt);

        // A new orphan appearing later is reaped by the replay and added to the receipt.
        $orphans = ['oe-orphan2'];
        $replay = $build()->release('NCK-123', releaseAttempt());
        expect($replay->networksReaped)
            ->toBe(['oe-orphan2'])
            ->and($store->read(releaseReceiptPath('NCK-123'))['networks_reaped'] ?? null)
            ->toBe(['oe-orphan1', 'oe-orphan2', 'orbit-e2e-n-legacy'])
            ->and($orphans)
            ->toBe([]);

        // A failing deletion is reported, the sweep continues, and every success is on the receipt.
        $orphans = ['oe-orphan3', 'oe-stuck', 'orbit-e2e-n-legacy2'];
        $replay = $build()->release('NCK-123', releaseAttempt());
        expect($replay->networksReaped)
            ->toBe(['oe-orphan3', 'orbit-e2e-n-legacy2'])
            ->and($replay->networksFailed)
            ->toHaveCount(1)
            ->and($replay->networksFailed[0])
            ->toStartWith('oe-stuck: ')
            ->and($store->read(releaseReceiptPath('NCK-123'))['networks_reaped'] ?? null)
            ->toBe(['oe-orphan1', 'oe-orphan2', 'oe-orphan3', 'orbit-e2e-n-legacy', 'orbit-e2e-n-legacy2'])
            ->and($store->read(releaseReceiptPath('NCK-123'))['networks_failed'] ?? null)
            ->toBe($replay->networksFailed)
            ->and($orphans)
            ->toBe(['oe-stuck']);

        // A repeated failure of the same network is recorded once on the receipt.
        $replay = $build()->release('NCK-123', releaseAttempt());
        expect($replay->networksFailed)
            ->toHaveCount(1)
            ->and($store->read(releaseReceiptPath('NCK-123'))['networks_failed'] ?? null)
            ->toBe($replay->networksFailed);
    });

    it('retains the proof record and writes a receipt when a proved attempt is released', function () {
        $paths = new StatePaths(temporaryPath('orbit-release-proved-', 8));
        $store = new AtomicJsonStore($paths);
        $target = featureTarget('NCK-123');
        readyProofReleaseState($store, $target->issue);
        $proof = ['schema' => 1, 'issue' => 'NCK-123', 'attempt_id' => releaseAttempt()->value, 'status' => 'proved'];
        $store->write('evidence/proofs/NCK-123/'.releaseAttempt()->value.'.json', $proof);
        $instances = [];
        foreach (TopologyProfile::ROLES as $role) {
            $instances[$target->instance($role)] = releaseInstanceJson($target, $role, []);
        }
        $networkExists = true;
        $commands = [];
        fakeReleaseIncus($target, $instances, $networkExists, $commands);

        $result = releaser($store, $paths)->release('NCK-123', releaseAttempt());

        expect($result->purpose)
            ->toBe(AttemptPurpose::Proof)
            ->and($result->released)
            ->not
            ->toContain('device:'.$target->instance('gateway').':orbit-source')
            ->and($result->verifiedAbsent)
            ->toBe([...array_map($target->instance(...), TopologyProfile::ROLES), $target->network()])
            ->and($store->read(releaseReceiptPath('NCK-123')))
            ->toBe($result->toArray())
            ->and($store->read('evidence/proofs/NCK-123/'.releaseAttempt()->value.'.json'))
            ->toBe($proof)
            ->and($store->read('topologies/NCK-123/active.json'))
            ->toBeNull()
            ->and($store->read('leases/NCK-123.json'))
            ->toBeNull()
            ->and($instances)
            ->toBe([])
            ->and($networkExists)
            ->toBeFalse();
    });

    it('refuses release when the deterministic topology MAC drifted', function () {
        $paths = new StatePaths(temporaryPath('orbit-release-mac-', 8));
        $store = new AtomicJsonStore($paths);
        $target = featureTarget('NCK-123');
        readyReleaseState($store, $target->issue);
        $instances = [];
        foreach (TopologyProfile::ROLES as $role) {
            $instances[$target->instance($role)] = releaseInstanceJson(
                $target,
                $role,
                mac: $role === 'gateway' ? '00:16:3e:00:00:00' : null,
            );
        }
        $networkExists = true;
        $commands = [];
        fakeReleaseIncus($target, $instances, $networkExists, $commands);

        expect(fn () => releaser($store, $paths)->release($target->issue, releaseAttempt()))
            ->toThrow(RuntimeException::class, 'identity does not match')
            ->and(collect($commands)->contains(
                static fn (array $command): bool => array_intersect($command, ['stop', 'delete']) !== [],
            ))
            ->toBeFalse()
            ->and($store->read(releaseReceiptPath('NCK-123')))
            ->toBeNull();
    });

    it('blocks deletion when a source mount does not match the attempt record', function (string $drift) {
        $paths = new StatePaths(temporaryPath('orbit-release-mount-', 8));
        $store = new AtomicJsonStore($paths);
        $target = featureTarget('NCK-123');
        readyReleaseState($store, $target->issue);
        $instances = [];
        foreach (TopologyProfile::ROLES as $role) {
            $mount = null;
            if ($role === 'gateway') {
                $mount = match ($drift) {
                    'source' => ['source' => '/srv/worktrees/other', 'path' => '/home/orbit/orbit'],
                    'path' => ['source' => RELEASE_MOUNT_SOURCE, 'path' => '/home/orbit/elsewhere'],
                    'missing' => [],
                    default => null,
                };
            }
            if ($role === 'app-prod' && $drift === 'unexpected') {
                $mount = ['source' => RELEASE_MOUNT_SOURCE, 'path' => '/home/orbit/orbit'];
            }
            $instances[$target->instance($role)] = releaseInstanceJson($target, $role, $mount);
        }
        $networkExists = true;
        $commands = [];
        fakeReleaseIncus($target, $instances, $networkExists, $commands);

        expect(fn () => releaser($store, $paths)->release($target->issue, releaseAttempt()))
            ->toThrow(RuntimeException::class, 'source mount')
            ->and(collect($commands)->contains(
                static fn (array $command): bool => array_intersect($command, ['stop', 'delete']) !== [],
            ))
            ->toBeFalse()
            ->and(count($instances))
            ->toBe(3)
            ->and($store->read(releaseTopologyPath('NCK-123')))
            ->not->toBeNull();
    })->with(['source', 'path', 'missing', 'unexpected']);

    it('refuses cleanup without the exact attempt', function () {
        $paths = new StatePaths(temporaryPath('orbit-release-', 8));
        $store = new AtomicJsonStore($paths);

        expect(fn () => releaser($store, $paths)->release('NCK-12', releaseAttempt()))
            ->toThrow(RuntimeException::class, 'NCK-12 has no active attempt.');
    });

    it('refuses release of an attempt the lease or the active pointer does not name', function () {
        $paths = new StatePaths(temporaryPath('orbit-release-', 8));
        $store = new AtomicJsonStore($paths);
        readyReleaseState($store);
        Process::preventStrayProcesses();

        expect(fn () => releaser($store, $paths)->release('NCK-12', releaseAttempt('b')))
            ->toThrow(RuntimeException::class, 'names another attempt');

        // Attempt b is the active one; attempt a keeps its record and the lease still names it.
        readyReleaseState($store, 'NCK-12', 'b');
        readyReleaseState($store);
        $store->write('topologies/NCK-12/active.json', [
            'schema' => 2,
            'issue' => 'NCK-12',
            'attempt' => releaseAttempt('b')->value,
        ]);

        expect(fn () => releaser($store, $paths)->release('NCK-12', releaseAttempt()))
            ->toThrow(RuntimeException::class, 'not the active topology attempt')
            ->and($store->read(releaseTopologyPath()))
            ->not->toBeNull();
        Process::assertNothingRan();
    });

    it('reports recorded resources as already absent on a repeated release', function () {
        $paths = new StatePaths(temporaryPath('orbit-release-', 8));
        $store = new AtomicJsonStore($paths);
        $target = featureTarget('NCK-12');
        $receipt = releaseReceipt([
            'released' => ['deleted:orbit-e2e-nck-12'],
            'absent' => ['orbit-e2e-nck-12-aaaaaaaa-app-prod'],
        ]);
        new ReleaseReceiptStore($store, $paths)->write($receipt);
        $commands = [];
        Process::fake(function (\Illuminate\Process\PendingProcess $process) use (&$commands) {
            $commands[] = $process->command;

            return Process::result('[]');
        });

        $result = releaser($store, $paths, operation: 'c')->release('NCK-12', releaseAttempt());

        expect($result->released)
            ->toBe([])
            ->and($result->alreadyAbsent)
            ->toBe(['deleted:orbit-e2e-nck-12', 'orbit-e2e-nck-12-aaaaaaaa-app-prod'])
            ->and($result->verifiedAbsent)
            ->toBe([...array_map($target->instance(...), TopologyProfile::ROLES), $target->network()])
            ->and($result->evidenceId)
            ->toBe(str_repeat('b', 32))
            ->and($result->operationId)
            ->toBe(str_repeat('c', 32))
            ->and($result->purpose)
            ->toBe(AttemptPurpose::Discovery)
            ->and($store->read(releaseReceiptPath()))
            ->toBe($receipt->toArray())
            ->and(collect($commands)->contains(
                static fn (array $command): bool => array_intersect($command, ['stop', 'delete']) !== [],
            ))
            ->toBeFalse();
    });

    it('touches nothing of a newer attempt when replaying an older release', function () {
        $paths = new StatePaths(temporaryPath('orbit-release-', 8));
        $store = new AtomicJsonStore($paths);
        readyReleaseState($store, 'NCK-12', 'b');
        new ReleaseReceiptStore($store, $paths)->write(releaseReceipt());
        $newerLease = $store->read('leases/NCK-12.json');
        $newerRecord = $store->read(releaseTopologyPath('NCK-12', 'b'));
        $newerPointer = $store->read('topologies/NCK-12/active.json');
        $commands = [];
        Process::fake(function (\Illuminate\Process\PendingProcess $process) use (&$commands) {
            $commands[] = $process->command;

            return Process::result('[]');
        });

        $result = releaser($store, $paths)->release('NCK-12', releaseAttempt());

        expect($result->attempt->value)
            ->toBe(releaseAttempt()->value)
            ->and($store->read('leases/NCK-12.json'))
            ->toBe($newerLease)
            ->and($store->read(releaseTopologyPath('NCK-12', 'b')))
            ->toBe($newerRecord)
            ->and($store->read('topologies/NCK-12/active.json'))
            ->toBe($newerPointer)
            ->and(collect($commands)->contains(
                static fn (array $command): bool => (
                    str_contains(implode(' ', $command), 'bbbbbbbb')
                    || array_intersect($command, ['stop', 'delete']) !== []
                ),
            ))
            ->toBeFalse();
    });

    it('refuses success when network deletion leaves the exact network present', function () {
        $paths = new StatePaths(temporaryPath('orbit-release-network-', 8));
        $store = new AtomicJsonStore($paths);
        readyReleaseState($store);
        Process::fake(function (\Illuminate\Process\PendingProcess $process) {
            $command = $process->command;
            if (
                ($command[0] ?? null) === 'python3'
                && str_ends_with((string) ($command[1] ?? ''), '/resources/host/reconcile-firewall.py')
            ) {
                return Process::result('{"changed":true}');
            }
            if (array_slice($command, 0, 5) === ['sudo', '-n', 'iptables', '-w', '5']) {
                return Process::result('', '', in_array('-C', $command, true) ? 1 : 0);
            }
            if (($command[3] ?? null) === 'list') {
                return Process::result('[]');
            }
            if (($command[3] ?? null) === 'network' && ($command[4] ?? null) === 'list') {
                return Process::result(json_encode([[
                    'name' => featureTarget('NCK-12')->network(),
                    'config' => [
                        'user.orbit.e2e.owner' => 'orbit-e2e',
                        'user.orbit.e2e.issue' => 'NCK-12',
                        'user.orbit.e2e.attempt' => releaseAttempt()->value,
                        'user.orbit.e2e.operation' => str_repeat('a', 32),
                    ],
                ]], JSON_THROW_ON_ERROR));
            }

            return Process::result();
        });

        expect(fn () => releaser($store, $paths)->release('NCK-12', releaseAttempt()))
            ->toThrow(RuntimeException::class, 'resources remain after release deletion');
        expect($store->read(releaseReceiptPath()))->toBeNull();
    });

    it('finishes an exact pending release after local finalization was interrupted', function () {
        $paths = new StatePaths(temporaryPath('orbit-release-', 8));
        $store = new AtomicJsonStore($paths);
        readyReleaseState($store);
        $result = releaseReceipt(['operation' => 'c', 'absent' => ['already-absent:old']]);
        $store->write(releasePendingPath(), [
            'schema' => 3,
            'issue' => 'NCK-12',
            'attempt' => releaseAttempt()->value,
            'acquisition_operation_id' => str_repeat('a', 32),
            'operation_id' => $result->operationId,
            'evidence_id' => $result->evidenceId,
            'lease_sha256' => releaseStateDigest($store->read('leases/NCK-12.json')),
            'topology_sha256' => releaseStateDigest($store->read(releaseTopologyPath())),
            'result' => $result->toArray(),
        ]);
        Process::fake(['*' => Process::result('[]')]);

        $replayed = releaser($store, $paths)->release('NCK-12', releaseAttempt());

        expect($replayed->operationId)
            ->toBe(str_repeat('c', 32))
            ->and($replayed->evidenceId)
            ->toBe(str_repeat('b', 32))
            ->and($replayed->released)
            ->toBe(['deleted:old'])
            ->and($replayed->alreadyAbsent)
            ->toBe(['already-absent:old'])
            ->and($store->read(releaseReceiptPath()))
            ->toBe($result->toArray())
            ->and($store->read(releasePendingPath()))
            ->toBeNull()
            ->and($store->read('leases/NCK-12.json'))
            ->toBeNull()
            ->and($store->read(releaseTopologyPath()))
            ->toBeNull()
            ->and($store->read('topologies/NCK-12/active.json'))
            ->toBeNull();
    });

    /**
     * The crash window `finalizePending()` must survive: the pending record is written and
     * the lease and the active pointer are already deleted, but no receipt exists.
     */
    function interruptedPendingRelease(AtomicJsonStore $store, string $issue = 'NCK-12'): void
    {
        $result = releaseReceipt(['operation' => 'c', 'issue' => $issue]);
        $store->write(releasePendingPath($issue), [
            'schema' => 3,
            'issue' => $issue,
            'attempt' => releaseAttempt()->value,
            'acquisition_operation_id' => str_repeat('a', 32),
            'operation_id' => $result->operationId,
            'evidence_id' => $result->evidenceId,
            'lease_sha256' => releaseStateDigest($store->read('leases/'.$issue.'.json')),
            'topology_sha256' => releaseStateDigest($store->read(releaseTopologyPath($issue))),
            'result' => $result->toArray(),
        ]);
        $store->delete('leases/'.$issue.'.json');
        $store->delete('topologies/'.$issue.'/active.json');
        $store->delete(releaseTopologyPath($issue));
    }

    it('finalizes a pending release whose lease and active pointer are already gone', function () {
        $paths = new StatePaths(temporaryPath('orbit-release-', 8));
        $store = new AtomicJsonStore($paths);
        readyReleaseState($store);
        $capacity = new HostCapacity($store, $paths, new OperationId(str_repeat('f', 32)), 12);
        $capacity->reserve('NCK-12', releaseAttempt(), new OperationId(str_repeat('a', 32)));
        interruptedPendingRelease($store);
        Process::fake(['*' => Process::result('[]')]);

        $replayed = releaser($store, $paths, $capacity)->release('NCK-12', releaseAttempt());

        expect($replayed->evidenceId)
            ->toBe(str_repeat('b', 32))
            ->and($store->read(releaseReceiptPath()))
            ->not
            ->toBeNull()
            ->and($store->read(releasePendingPath()))
            ->toBeNull()
            ->and($store->read('capacity/incus.json'))
            ->toBe(['schema' => 2, 'reservations' => []]);
    });

    it('refuses a pending release without a lease while an exact resource remains', function () {
        $paths = new StatePaths(temporaryPath('orbit-release-', 8));
        $store = new AtomicJsonStore($paths);
        readyReleaseState($store);
        $capacity = new HostCapacity($store, $paths, new OperationId(str_repeat('f', 32)), 12);
        $capacity->reserve('NCK-12', releaseAttempt(), new OperationId(str_repeat('a', 32)));
        $ledger = $store->read('capacity/incus.json');
        interruptedPendingRelease($store);
        Process::fake(function (\Illuminate\Process\PendingProcess $process) {
            $command = $process->command;
            if (($command[3] ?? null) === 'network' && ($command[4] ?? null) === 'list') {
                return Process::result(json_encode(
                    [['name' => featureTarget('NCK-12')->network(), 'config' => []]],
                    JSON_THROW_ON_ERROR,
                ));
            }

            return Process::result('[]');
        });

        expect(fn () => releaser($store, $paths, $capacity)->release('NCK-12', releaseAttempt()))
            ->toThrow(
                RuntimeException::class,
                'Cannot finalize pending release while an exact topology resource exists',
            )
            ->and($store->read(releaseReceiptPath()))
            ->toBeNull()
            ->and($store->read(releasePendingPath()))
            ->not
            ->toBeNull()
            ->and($store->read('capacity/incus.json'))
            ->toBe($ledger);
    });

    it('preserves active state that does not match pending release identity', function () {
        $paths = new StatePaths(temporaryPath('orbit-release-', 8));
        $store = new AtomicJsonStore($paths);
        readyReleaseState($store);
        $result = releaseReceipt(['operation' => 'c', 'released' => []]);
        $store->write(releasePendingPath(), [
            'schema' => 3,
            'issue' => 'NCK-12',
            'attempt' => releaseAttempt()->value,
            'acquisition_operation_id' => str_repeat('a', 32),
            'operation_id' => $result->operationId,
            'evidence_id' => $result->evidenceId,
            'lease_sha256' => releaseStateDigest(['state' => 'different']),
            'topology_sha256' => releaseStateDigest($store->read(releaseTopologyPath())),
            'result' => $result->toArray(),
        ]);
        Process::preventStrayProcesses();

        expect(fn () => releaser($store, $paths)->release('NCK-12', releaseAttempt()))
            ->toThrow(RuntimeException::class, 'pending release state does not match');
        expect($store->read('leases/NCK-12.json'))
            ->not->toBeNull()->and($store->read(releaseTopologyPath()))
            ->not->toBeNull()->and($store->read(releasePendingPath()))
            ->not->toBeNull()->and($store->read(releaseReceiptPath()))->toBeNull();
        Process::assertNothingRan();
    });
});
