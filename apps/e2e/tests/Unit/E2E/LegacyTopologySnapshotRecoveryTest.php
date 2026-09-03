<?php

declare(strict_types=1);

use App\E2E\ColdTopologyConstructor;
use App\E2E\Git\GitRepository;
use App\E2E\HostCapacity;
use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\LaravelReleaseResolver;
use App\E2E\LegacyTopologySnapshotRecovery;
use App\E2E\PreparedStateFingerprint;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\OperationLock;
use App\E2E\State\StatePaths;
use App\E2E\TopologyConverger;
use App\E2E\TopologySnapshotAvailability;
use App\E2E\TopologySnapshotBuilder;
use App\E2E\TopologySnapshotManifestStore;
use App\E2E\TopologySnapshotRebuilder;
use App\E2E\TopologySnapshotRefresher;
use App\E2E\TopologyVerifier;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\LegacyTopologySnapshotInventory;
use App\E2E\Value\OperationId;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologySnapshotGeneration;
use App\E2E\Value\TopologySnapshotIdentity;
use App\E2E\Value\TopologyTarget;
use App\E2E\WorktreeSynchronizer;
use Illuminate\Container\Container;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

final class LegacyRecoveryHost
{
    /** @var list<string> */
    public array $instances = [];

    public bool $networkPresent = true;

    /** @var array<string, array<string, string>> */
    public array $metadata = [];

    /** @var array<string, string> */
    public array $networks = [];

    /** @var array<string, string> */
    public array $macs = [];

    /** @var array<string, array<string, array{source:string,path:string}>> */
    public array $disks = [];

    public string $networkOwner = 'orbit-e2e';

    public string $networkAddress = '10.232.1.1/24';

    /** @var list<string> */
    public array $networkUsers = [];

    /** @var array<string, list<string>> */
    public array $snapshots = [];
}

function legacyRecoveryGeneration(int $schema = TopologySnapshotGeneration::SCHEMA): TopologySnapshotGeneration
{
    $generation = new TopologySnapshotGeneration(
        'legacy-generation',
        str_repeat('b', 40),
        array_fill_keys(TopologyProfile::ROLES, 'main-legacy-generation'),
        str_repeat('c', 64),
        str_repeat('d', 64),
        new LaravelRelease('v13.10.1', str_repeat('e', 40)),
        str_repeat('f', 64),
        2,
        'ubuntu-26.04-amd64-v1',
        'orbit-base-ubuntu-26.04-runtime',
        TopologyProfile::NAME,
        TopologyProfile::ROLES,
        TopologyProfile::CHECKOUT_ROLES,
    );
    if ($schema === TopologySnapshotGeneration::SCHEMA) {
        return $generation;
    }

    $legacy = $generation->toArray();
    $legacy['schema'] = TopologySnapshotGeneration::LEGACY_SCHEMA;
    $legacy['prepared_schema'] = 1;
    unset($legacy['topology']['assignments']);

    return TopologySnapshotGeneration::fromArray($legacy);
}

/** @return array<string, string> */
function legacyRecoveryMacs(?TopologySnapshotIdentity $identity = null): array
{
    $target = TopologyTarget::topologySnapshot($identity ?? TopologySnapshotIdentity::primary());
    $macs = [];
    foreach (TopologyProfile::ROLES as $role) {
        $macs[$role] = $target->mac($role);
    }

    return $macs;
}

/** @mago-expect lint:cyclomatic-complexity The fake models each exact Incus inventory and mutation command. */
function fakeLegacyRecoveryHost(
    LegacyRecoveryHost $state,
    ?TopologySnapshotIdentity $identity = null,
): void {
    $identity ??= TopologySnapshotIdentity::primary();
    $macs = legacyRecoveryMacs($identity);
    /** @mago-expect lint:cyclomatic-complexity One process fake keeps the mutable host inventory coherent. */
    Process::fake(function (PendingProcess $process) use ($state, $identity, $macs): ProcessResult {
        $command = $process->command;
        assert(is_array($command));
        if (($command[3] ?? null) === 'network' && ($command[4] ?? null) === 'list') {
            $networks = $state->networkPresent
                ? [[
                    'name' => $identity->network(),
                    'type' => 'bridge',
                    'managed' => true,
                    'config' => [
                        'user.orbit.e2e.owner' => $state->networkOwner,
                        'user.orbit.e2e.operation' => str_repeat('a', 32),
                        'ipv4.address' => $state->networkAddress,
                        'ipv4.nat' => 'true',
                        'ipv4.dhcp.ranges' => '10.232.1.10-10.232.1.12',
                        'ipv6.address' => 'none',
                        'raw.dnsmasq' => 'port=0',
                    ],
                    'used_by' => $state->networkUsers,
                ]] : [];

            return Process::result(json_encode($networks, JSON_THROW_ON_ERROR));
        }
        if (($command[3] ?? null) === 'network' && ($command[4] ?? null) === 'delete') {
            $state->networkPresent = false;

            return Process::result();
        }
        if (($command[0] ?? null) === 'python3') {
            return Process::result(json_encode(['changed' => true], JSON_THROW_ON_ERROR));
        }
        if (($command[3] ?? null) === 'snapshot' && ($command[4] ?? null) === 'list') {
            $name = preg_replace('/\A[^:]+:/', '', (string) ($command[5] ?? ''));

            return Process::result(json_encode(array_map(
                static fn (string $snapshot): array => [
                    'name' => $snapshot,
                    'created_at' => '2026-09-01T12:00:00Z',
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                ],
                $state->snapshots[$name] ?? [],
            ), JSON_THROW_ON_ERROR));
        }
        if (($command[3] ?? null) === 'list') {
            $resources = [];
            foreach ($state->instances as $name) {
                $role = str_replace([$identity->instancePrefix(), '-next'], '', $name);
                $resources[] = [
                    'name' => $name,
                    'type' => 'virtual-machine',
                    'status' => 'Stopped',
                    'status_code' => 102,
                    'config' => $state->metadata[$name] ?? [
                        'user.orbit.e2e.owner' => 'orbit-e2e',
                        'user.orbit.e2e.operation' => str_repeat('a', 32),
                    ],
                    'devices' => [
                        'root' => ['pool' => 'orbit-e2e'],
                        'eth0' => [
                            'network' => $state->networks[$name] ?? $identity->network(),
                            'hwaddr' => $state->macs[$name] ?? $macs[$role],
                        ],
                        ...array_map(
                            static fn (array $disk): array => ['type' => 'disk', ...$disk],
                            $state->disks[$name] ?? [],
                        ),
                    ],
                ];
            }

            return Process::result(json_encode($resources, JSON_THROW_ON_ERROR));
        }
        if (($command[3] ?? null) === 'delete') {
            $name = preg_replace('/\A[^:]+:/', '', (string) ($command[4] ?? ''));
            $state->instances = array_values(array_diff($state->instances, [$name]));

            return Process::result();
        }
        if (($command[3] ?? null) === 'stop') {
            return Process::result();
        }

        return Process::result();
    });
}

/** @return array{0:LegacyTopologySnapshotRecovery,1:AtomicJsonStore,2:LegacyRecoveryHost,3:StatePaths} */
function legacyRecoveryService(
    int $schema = TopologySnapshotGeneration::SCHEMA,
    ?TopologySnapshotIdentity $identity = null,
    string $stateDirectory = 'topology-snapshot',
): array {
    $identity ??= TopologySnapshotIdentity::primary();
    $hostState = new LegacyRecoveryHost;
    $hostState->instances = $identity->instances();
    foreach ($identity->instances() as $name) {
        $hostState->snapshots[$name] = ['main-legacy-generation'];
        $hostState->networkUsers[] = "/1.0/instances/{$name}?project=default";
    }
    fakeLegacyRecoveryHost($hostState, $identity);
    $paths = new StatePaths(temporaryPath('orbit-legacy-recovery-', 4));
    $store = new AtomicJsonStore($paths);
    $host = new IncusHost(project: 'default', pool: 'orbit-e2e');
    $manifests = new TopologySnapshotManifestStore($store, $paths, $host, $stateDirectory);
    $generation = legacyRecoveryGeneration($schema);
    $manifests->promote($generation);
    $manifests->record($generation);

    return [
        new LegacyTopologySnapshotRecovery(
            $host,
            $manifests,
            $store,
            new OperationId(str_repeat('a', 32)),
            $identity,
        ),
        $store,
        $hostState,
        $paths,
    ];
}

function legacyRecoveryRefresher(
    IncusHost $host,
    StatePaths $paths,
    AtomicJsonStore $state,
    TopologySnapshotManifestStore $manifests,
    OperationId $operation,
): TopologySnapshotRefresher {
    $root = dirname(__DIR__, 4);
    $git = new GitRepository($root);
    $identity = TopologySnapshotIdentity::primary();
    $synchronizer = new WorktreeSynchronizer($host, $root, $operation);
    $converger = new TopologyConverger($host);
    $verifier = new TopologyVerifier($host, 1, 10_000);

    return new TopologySnapshotRefresher(
        $host,
        new IncusNetworkLifecycle($host),
        new PreparedStateFingerprint($git),
        $manifests,
        new TopologySnapshotBuilder(
            new ColdTopologyConstructor(
                $host,
                new IncusNetworkLifecycle($host),
                $synchronizer,
                $converger,
                new HostCapacity($host, 9),
                $paths,
            ),
            $manifests,
            $state,
            $root,
            $identity,
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
        $identity,
        new TopologySnapshotAvailability($host, $identity),
        0,
    );
}

beforeEach(function (): void {
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
});

it('authorizes exact schema 4 and 5 topology snapshot resources without mutation', function (int $schema): void {
    [$recovery, $store] = legacyRecoveryService($schema);

    $inventory = $recovery->authorize();

    expect($inventory->resourceNames())->toBe([
        'oe-topo-snap',
        'orbit-e2e-topology-snapshot-app-dev',
        'orbit-e2e-topology-snapshot-app-prod',
        'orbit-e2e-topology-snapshot-gateway',
    ]);
    expect($inventory->toArray()['scope'])->toBe([
        'remote' => 'local',
        'project' => 'default',
        'pool' => 'orbit-e2e',
    ]);
    expect($inventory->toArray()['schema'])->toBe(2);
    expect($inventory->sha256())->toMatch('/\A[a-f0-9]{64}\z/');
    expect($store->read('topology-snapshot/recovery.json'))->toBeNull();
})->with([
    'schema 4' => TopologySnapshotGeneration::LEGACY_SCHEMA,
    'schema 5' => TopologySnapshotGeneration::SCHEMA,
]);

it('reads retained schema 1 evidence only for the former unnamespaced snapshot', function (): void {
    [$recovery] = legacyRecoveryService();
    $value = $recovery->authorize()->toArray();
    $value['schema'] = 1;
    $value['scope']['topology_snapshot_namespace'] = '';

    expect(LegacyTopologySnapshotInventory::fromArray($value)->toArray())
        ->toBe($value);

    $value['scope']['topology_snapshot_namespace'] = 'live';

    expect(fn () => LegacyTopologySnapshotInventory::fromArray($value))
        ->toThrow(InvalidArgumentException::class, 'The legacy topology snapshot inventory is invalid.');
});

it('authorizes the retired physical identity and its isolated manifest for migration', function (): void {
    [$recovery, $store] = legacyRecoveryService(
        identity: TopologySnapshotIdentity::retired(),
        stateDirectory: 'standby',
    );

    expect($recovery->authorize()->resourceNames())
        ->toBe([
            'oe-standby',
            'orbit-e2e-standby-app-dev',
            'orbit-e2e-standby-app-prod',
            'orbit-e2e-standby-gateway',
        ])
        ->and($store->read('standby/promoted.json'))
        ->not
        ->toBeNull()
        ->and($store->read('topology-snapshot/promoted.json'))
        ->toBeNull();
});

it('refuses foreign instance ownership without writing recovery evidence', function (): void {
    [$recovery, $store, $host] = legacyRecoveryService();
    $host->metadata['orbit-e2e-topology-snapshot-gateway'] = [
        'user.orbit.e2e.owner' => 'foreign',
        'user.orbit.e2e.operation' => str_repeat('a', 32),
    ];

    expect(fn () => $recovery->authorize())
        ->toThrow(RuntimeException::class, 'orbit-e2e-topology-snapshot-gateway ownership does not match');
    expect($store->read('topology-snapshot/recovery.json'))->toBeNull();
});

it('authorizes project-less network users in the default Incus project', function (): void {
    [$recovery, , $host] = legacyRecoveryService();
    $host->networkUsers = array_map(
        static fn (string $user): string => (string) parse_url($user, PHP_URL_PATH),
        $host->networkUsers,
    );

    expect($recovery->authorize()->resourceNames())->toContain('oe-topo-snap');
});

it('authorizes an exact promotion copy with complete attempt identity', function (): void {
    [$recovery, , $host] = legacyRecoveryService();
    $copy = 'orbit-e2e-topology-snapshot-gateway-next';
    $host->instances[] = $copy;
    $host->metadata[$copy] = [
        'user.orbit.e2e.owner' => 'orbit-e2e',
        'user.orbit.e2e.operation' => str_repeat('a', 32),
        'user.orbit.e2e.issue' => 'AUX-92',
        'user.orbit.e2e.attempt' => str_repeat('b', 32),
    ];
    $host->networkUsers[] = "/1.0/instances/{$copy}?project=default";

    $inventory = $recovery->authorize();

    expect($inventory->resourceNames())->toContain($copy);
});

it('fails closed on incomplete or ambiguous ownership evidence', function (Closure $mutate, string $error): void {
    [$recovery, $store, $host] = legacyRecoveryService();
    $promoted = $store->read('topology-snapshot/promoted.json');
    $mutate($host);

    expect(fn () => $recovery->authorize())->toThrow(RuntimeException::class, $error);
    expect($store->read('topology-snapshot/recovery.json'))->toBeNull();
    expect($store->read('topology-snapshot/promoted.json'))->toBe($promoted);
})->with([
    'missing operation identity' => [
        function (LegacyRecoveryHost $host): void {
            $host->metadata['orbit-e2e-topology-snapshot-gateway'] = ['user.orbit.e2e.owner' => 'orbit-e2e'];
        },
        'operation identity is incomplete',
    ],
    'wrong network attachment' => [
        function (LegacyRecoveryHost $host): void {
            $host->networks['orbit-e2e-topology-snapshot-gateway'] = 'oe-other';
        },
        'network identity does not match',
    ],
    'wrong deterministic MAC' => [
        function (LegacyRecoveryHost $host): void {
            $host->macs['orbit-e2e-topology-snapshot-gateway'] = '00:16:3e:00:00:00';
        },
        'MAC identity does not match',
    ],
    'unexpected host disk' => [
        function (LegacyRecoveryHost $host): void {
            $host->disks['orbit-e2e-topology-snapshot-gateway'] = [
                'source' => ['source' => '/tmp/foreign', 'path' => '/mnt/foreign'],
            ];
        },
        'unexpected disk devices',
    ],
    'feature-owned base VM' => [
        function (LegacyRecoveryHost $host): void {
            $host->metadata['orbit-e2e-topology-snapshot-gateway'] = [
                'user.orbit.e2e.owner' => 'orbit-e2e',
                'user.orbit.e2e.operation' => str_repeat('a', 32),
                'user.orbit.e2e.issue' => 'AUX-92',
            ];
        },
        'belongs to feature issue AUX-92',
    ],
    'unexpected instance metadata' => [
        function (LegacyRecoveryHost $host): void {
            $host->metadata['orbit-e2e-topology-snapshot-gateway'] = [
                'user.orbit.e2e.owner' => 'orbit-e2e',
                'user.orbit.e2e.operation' => str_repeat('a', 32),
                'user.orbit.e2e.attempt' => str_repeat('b', 32),
            ];
        },
        'ownership identity is incomplete',
    ],
    'missing promoted snapshot' => [
        function (LegacyRecoveryHost $host): void {
            $host->snapshots['orbit-e2e-topology-snapshot-gateway'] = [];
        },
        'does not contain promoted snapshot',
    ],
    'foreign network owner' => [
        function (LegacyRecoveryHost $host): void {
            $host->networkOwner = 'foreign';
        },
        'network oe-topo-snap ownership does not match',
    ],
    'wrong network subnet' => [
        function (LegacyRecoveryHost $host): void {
            $host->networkAddress = '10.232.199.1/24';
        },
        'configuration identity does not match',
    ],
    'unexpected network user' => [
        function (LegacyRecoveryHost $host): void {
            $host->networkUsers[] = '/1.0/instances/foreign?project=default';
        },
        'has an unexpected user',
    ],
    'duplicate network user' => [
        function (LegacyRecoveryHost $host): void {
            $host->networkUsers[] = $host->networkUsers[0];
        },
        'user identity is ambiguous',
    ],
    'duplicate exact instance' => [
        function (LegacyRecoveryHost $host): void {
            $host->instances[] = 'orbit-e2e-topology-snapshot-gateway';
        },
        'appears more than once in inventory',
    ],
]);

it('retains hash-bound authorization and ordered mutation evidence', function (): void {
    [$recovery, $store] = legacyRecoveryService();
    $inventory = $recovery->authorize();

    $recovery->start(str_repeat('b', 40), $inventory);
    $recovery->record('instances_pending', ['instances' => $inventory->resourceNames()]);
    $recovery->record('instances_verified', ['instances_deleted' => $inventory->resourceNames()]);

    expect($store->read('topology-snapshot/recovery.json'))->toBe([
        'schema' => 1,
        'operation_id' => str_repeat('a', 32),
        'main_sha' => str_repeat('b', 40),
        'inventory_sha256' => $inventory->sha256(),
        'inventory' => $inventory->toArray(),
        'phase' => 'instances_verified',
        'history' => [
            ['phase' => 'authorized', 'evidence' => ['resources' => $inventory->resourceNames()]],
            ['phase' => 'instances_pending', 'evidence' => ['instances' => $inventory->resourceNames()]],
            [
                'phase' => 'instances_verified',
                'evidence' => ['instances_deleted' => $inventory->resourceNames()],
            ],
        ],
    ]);
    expect($store->read('topology-snapshot/promoted.json'))->toBe($inventory->promotedManifest);
});

it('round trips a network-only authorization through retained JSON and a fresh recovery process', function (): void {
    [$recovery, $store, $host, $paths] = legacyRecoveryService();
    $host->instances = [];
    $host->networkUsers = [];
    $inventory = $recovery->authorize();
    $mainSha = str_repeat('b', 40);

    $recovery->start($mainSha, $inventory);
    $written = json_decode(
        (string) file_get_contents($paths->path('topology-snapshot/recovery.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $fresh = new LegacyTopologySnapshotRecovery(
        new IncusHost(project: 'default', pool: 'orbit-e2e'),
        new TopologySnapshotManifestStore($store, $paths, new IncusHost(project: 'default', pool: 'orbit-e2e')),
        $store,
        new OperationId(str_repeat('c', 32)),
        TopologySnapshotIdentity::primary(),
    );

    expect($written['inventory']['instances'] ?? null)
        ->toBe([])
        ->and($written['inventory']['snapshots'] ?? null)
        ->toBe([])
        ->and(LegacyTopologySnapshotInventory::fromArray($written['inventory'])->toArray())
        ->toBe($inventory->toArray())
        ->and($fresh->resume($mainSha)?->toArray())
        ->toBe($inventory->toArray());
});

it('archives a completed network-only record before the next recovery starts', function (): void {
    [$recovery, $store, $host, $paths] = legacyRecoveryService();
    $host->instances = [];
    $host->networkUsers = [];
    $inventory = $recovery->authorize();
    $mainSha = str_repeat('b', 40);
    $recovery->start($mainSha, $inventory);
    $recovery->record('construction_verified', [
        'generation_id' => 'network-only-replacement',
        'main_sha' => $mainSha,
        'next_action' => 'bin/e2e-topology-snapshot status',
    ]);
    $completed = $store->read('topology-snapshot/recovery.json');
    $next = new LegacyTopologySnapshotRecovery(
        new IncusHost(project: 'default', pool: 'orbit-e2e'),
        new TopologySnapshotManifestStore($store, $paths, new IncusHost(project: 'default', pool: 'orbit-e2e')),
        $store,
        new OperationId(str_repeat('c', 32)),
        TopologySnapshotIdentity::primary(),
    );

    $next->start(str_repeat('d', 40), $next->authorize());

    expect($store->read('topology-snapshot/recoveries/'.str_repeat('a', 32).'.json'))
        ->toBe($completed)
        ->and($store->read('topology-snapshot/recovery.json'))
        ->operation_id->toBe(str_repeat('c', 32))
        ->main_sha->toBe(str_repeat('d', 40))
        ->phase->toBe('authorized');
});

it('rejects non-empty lists and mixed map shapes in retained inventory', function (Closure $mutate): void {
    [$recovery] = legacyRecoveryService();
    $value = $recovery->authorize()->toArray();
    $mutate($value);

    expect(fn () => LegacyTopologySnapshotInventory::fromArray($value))
        ->toThrow(\InvalidArgumentException::class, 'The legacy topology snapshot inventory is invalid.');
})->with([
    'non-empty instance list' => [function (array &$value): void {
        $value['instances'] = [['name' => 'orbit-e2e-topology-snapshot-gateway']];
    }],
    'non-empty snapshot list' => [function (array &$value): void {
        $value['snapshots'] = [['name' => 'main-legacy-generation']];
    }],
    'mixed instance map' => [function (array &$value): void {
        $value['instances'][0] = ['name' => 'foreign'];
    }],
    'mixed snapshot map' => [function (array &$value): void {
        $value['snapshots'][0] = [['name' => 'foreign', 'created_at' => '2026-09-01T12:00:00Z']];
    }],
]);

it('archives completed evidence before starting a separately authorized recovery', function (): void {
    [$recovery, $store, , $paths] = legacyRecoveryService();
    $inventory = $recovery->authorize();
    $mainSha = str_repeat('b', 40);
    $recovery->start($mainSha, $inventory);
    $recovery->record('construction_verified', [
        'generation_id' => 'legacy-generation',
        'main_sha' => $mainSha,
        'next_action' => 'bin/e2e-topology-snapshot status',
    ]);
    $completed = $store->read('topology-snapshot/recovery.json');
    $next = new LegacyTopologySnapshotRecovery(
        new IncusHost(project: 'default', pool: 'orbit-e2e'),
        new TopologySnapshotManifestStore($store, $paths, new IncusHost(project: 'default', pool: 'orbit-e2e')),
        $store,
        new OperationId(str_repeat('c', 32)),
        TopologySnapshotIdentity::primary(),
    );

    $nextInventory = $next->authorize();
    $next->start(str_repeat('d', 40), $nextInventory);

    expect($store->read('topology-snapshot/recoveries/'.str_repeat('a', 32).'.json'))->toBe($completed);
    expect($store->read('topology-snapshot/recovery.json'))
        ->operation_id->toBe(str_repeat('c', 32))
        ->main_sha->toBe(str_repeat('d', 40))
        ->phase->toBe('authorized');
});

it('keeps one refresh lock through teardown and the construction boundary', function (): void {
    $paths = new StatePaths(temporaryPath('orbit-legacy-recovery-lock-', 4));
    $operation = new OperationId(str_repeat('a', 32));
    $blocked = null;
    $interrupted = false;
    $store = new AtomicJsonStore(
        $paths,
        function (string $stage, string $temporary) use ($paths, &$blocked, &$interrupted): void {
            if ($stage !== 'before_rename' || $interrupted) {
                return;
            }
            $pending = json_decode((string) file_get_contents($temporary), true, 512, JSON_THROW_ON_ERROR);
            if (($pending['phase'] ?? null) !== 'manifests_verified') {
                return;
            }

            $contender = new OperationLock($paths);
            $acquired = $contender->acquire(
                'standby-refresh',
                new OperationId(str_repeat('c', 32)),
                timeoutSeconds: 0,
            );
            $blocked = ! $acquired;
            if ($acquired) {
                $contender->release();
            }
            $interrupted = true;

            throw new RuntimeException('Interrupted after verified teardown.');
        },
    );
    $identity = TopologySnapshotIdentity::primary();
    $hostState = new LegacyRecoveryHost;
    $hostState->instances = $identity->instances();
    foreach ($identity->instances() as $name) {
        $hostState->snapshots[$name] = ['main-legacy-generation'];
        $hostState->networkUsers[] = "/1.0/instances/{$name}?project=default";
    }
    fakeLegacyRecoveryHost($hostState);
    $host = new IncusHost(project: 'default', pool: 'orbit-e2e');
    $manifests = new TopologySnapshotManifestStore($store, $paths, $host);
    $generation = legacyRecoveryGeneration();
    $manifests->promote($generation);
    $manifests->record($generation);
    $recovery = new LegacyTopologySnapshotRecovery($host, $manifests, $store, $operation, $identity);
    $rebuilder = new TopologySnapshotRebuilder(
        $host,
        new IncusNetworkLifecycle($host),
        $manifests,
        $paths,
        new OperationLock($paths),
        $operation,
        $identity,
    );
    $refresher = legacyRecoveryRefresher($host, $paths, $store, $manifests, $operation);
    $sha = str_repeat('b', 40);

    $result = $refresher->recoverLegacy($sha, $recovery, $rebuilder);

    expect($result->state)->toBe('failed');
    expect($result->error)->toContain('Interrupted after verified teardown.');
    expect($blocked)->toBeTrue();
    $after = new OperationLock($paths);
    expect($after->acquire(
        'standby-refresh',
        new OperationId(str_repeat('d', 32)),
        timeoutSeconds: 0,
    ))->toBeTrue();
    $after->release();
});

it('resumes retained evidence only for the same SHA and inventory digest', function (): void {
    [$recovery, $store, , $paths] = legacyRecoveryService();
    $inventory = $recovery->authorize();
    $recovery->start(str_repeat('b', 40), $inventory);
    $recovery->record('manifests_verified', ['promoted_manifest_retained' => $inventory->promotedManifest]);
    $resumed = new LegacyTopologySnapshotRecovery(
        new IncusHost(project: 'default', pool: 'orbit-e2e'),
        new TopologySnapshotManifestStore($store, $paths, new IncusHost(project: 'default', pool: 'orbit-e2e')),
        $store,
        new OperationId(str_repeat('c', 32)),
        TopologySnapshotIdentity::primary(),
    );

    expect(fn () => $resumed->resume(str_repeat('d', 40)))
        ->toThrow(RuntimeException::class, 'does not match the requested main SHA');
    $authorization = $resumed->resume(str_repeat('b', 40));

    expect($authorization?->sha256())->toBe($inventory->sha256());
    expect($store->read('topology-snapshot/recovery.json')['operation_id'] ?? null)->toBe(str_repeat('c', 32));
    expect($store->read('topology-snapshot/recovery.json')['phase'] ?? null)->toBe('resumed');
});

it('retains the exact interrupted construction operation across retries', function (): void {
    [$recovery, $store, , $paths] = legacyRecoveryService();
    $inventory = $recovery->authorize();
    $mainSha = str_repeat('b', 40);
    $recovery->start($mainSha, $inventory);
    $recovery->record('construction_pending', [
        'operation_id' => str_repeat('a', 32),
        'next_action' => "bin/e2e-topology-snapshot recover-legacy --main-sha={$mainSha}",
    ]);
    $firstRetry = new LegacyTopologySnapshotRecovery(
        new IncusHost(project: 'default', pool: 'orbit-e2e'),
        new TopologySnapshotManifestStore($store, $paths, new IncusHost(project: 'default', pool: 'orbit-e2e')),
        $store,
        new OperationId(str_repeat('c', 32)),
        TopologySnapshotIdentity::primary(),
    );

    $interrupted = $firstRetry->interruptedConstructionOperation();
    $firstRetry->resume($mainSha);
    $firstRetry->record('construction_cleanup_pending', [
        'operation_id' => $interrupted?->value,
        'next_action' => "bin/e2e-topology-snapshot recover-legacy --main-sha={$mainSha}",
    ]);
    $secondRetry = new LegacyTopologySnapshotRecovery(
        new IncusHost(project: 'default', pool: 'orbit-e2e'),
        new TopologySnapshotManifestStore($store, $paths, new IncusHost(project: 'default', pool: 'orbit-e2e')),
        $store,
        new OperationId(str_repeat('d', 32)),
        TopologySnapshotIdentity::primary(),
    );

    expect($interrupted?->value)->toBe(str_repeat('a', 32));
    expect($secondRetry->interruptedConstructionOperation()?->value)->toBe(str_repeat('a', 32));
});
