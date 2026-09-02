<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\OperationLock;
use App\E2E\State\StatePaths;
use App\E2E\TopologySnapshotManifestStore;
use App\E2E\TopologySnapshotRebuilder;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\LegacyTopologySnapshotInventory;
use App\E2E\Value\OperationId;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologySnapshotGeneration;
use App\E2E\Value\TopologySnapshotIdentity;
use Illuminate\Container\Container;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

/** The host as the recovery finds it: topology snapshot VMs and a network without a usable generation. */
final class RebuildHost
{
    /** @var list<string> */
    public array $instances = [];

    /** @var list<string> */
    public array $networks = [];

    /** @var list<string> */
    public array $deleted = [];

    /** @var array<string, string> */
    public array $instanceMetadata = [];

    public bool $networkOwned = true;
}

function rebuildIdentity(): TopologySnapshotIdentity
{
    return TopologySnapshotIdentity::live();
}

function rebuildGeneration(string $id): TopologySnapshotGeneration
{
    return new TopologySnapshotGeneration(
        $id,
        str_repeat('b', 40),
        array_fill_keys(TopologyProfile::ROLES, 'main-'.$id),
        str_repeat('c', 64),
        str_repeat('d', 64),
        new LaravelRelease('v13.10.1', str_repeat('e', 40)),
        str_repeat('f', 64),
        2,
        'ubuntu-26.04-amd64-v1',
        'orbit-base-ubuntu-26.04-runtime',
        'gateway_app-dev_app-prod',
        TopologyProfile::ROLES,
        ['gateway', 'app-dev'],
    );
}

function legacyRebuildGeneration(string $id): TopologySnapshotGeneration
{
    $legacy = rebuildGeneration($id)->toArray();
    $legacy['schema'] = TopologySnapshotGeneration::LEGACY_SCHEMA;
    $legacy['prepared_schema'] = 1;
    unset($legacy['topology']['assignments']);

    return TopologySnapshotGeneration::fromArray($legacy);
}

/** @mago-expect lint:cyclomatic-complexity The fake preserves one coherent Incus resource inventory. */
function fakeRebuildHost(RebuildHost $state): void
{
    /** @mago-expect lint:cyclomatic-complexity One process fake models every inventory and mutation command. */
    Process::fake(function (PendingProcess $process) use ($state): ProcessResult {
        $command = $process->command;
        assert(is_array($command), 'Incus uses argument arrays.');
        if (($firewall = rebuildFirewallResult($command)) !== null) {
            return $firewall;
        }
        if (($command[3] ?? null) === 'network' && ($command[4] ?? null) === 'list') {
            return Process::result(rebuildNetworkInventoryJson($state));
        }
        if (($command[3] ?? null) === 'network' && ($command[4] ?? null) === 'delete') {
            $name = preg_replace('/\A[^:]+:/', '', (string) ($command[5] ?? ''));
            $state->networks = array_values(array_diff($state->networks, [$name]));
            $state->deleted[] = $name;

            return Process::result();
        }
        if (($command[3] ?? null) === 'delete') {
            $name = preg_replace('/\A[^:]+:/', '', (string) ($command[4] ?? ''));
            $state->instances = array_values(array_diff($state->instances, [$name]));
            $state->deleted[] = $name;

            return Process::result();
        }
        if (($command[3] ?? null) === 'stop') {
            return Process::result();
        }
        if (($command[3] ?? null) === 'snapshot' && ($command[4] ?? null) === 'list') {
            return Process::result('[]');
        }

        return Process::result(rebuildInstanceInventoryJson($state));
    });
}

/** @param list<string> $command */
function rebuildFirewallResult(array $command): ?ProcessResult
{
    return (
        ($command[0] ?? null) === 'python3'
            ? Process::result(json_encode(['changed' => true], JSON_THROW_ON_ERROR))
            : null
    );
}

function rebuildInstanceInventoryJson(RebuildHost $state): string
{
    return json_encode(array_map(
        static fn (string $name): array => [
            'name' => $name,
            'type' => 'virtual-machine',
            'status' => 'Stopped',
            'status_code' => 102,
            'config' => $state->instanceMetadata[$name] ?? ['user.orbit.e2e.owner' => 'orbit-e2e'],
            'devices' => ['root' => ['pool' => 'orbit-e2e']],
        ],
        $state->instances,
    ), JSON_THROW_ON_ERROR);
}

function rebuildNetworkInventoryJson(RebuildHost $state): string
{
    return json_encode(array_map(
        static fn (string $name): array => [
            'name' => $name,
            'type' => 'bridge',
            'managed' => true,
            'config' => $state->networkOwned
                ? ['user.orbit.e2e.owner' => 'orbit-e2e', 'ipv4.address' => '10.232.200.1/24']
                : ['ipv4.address' => '10.232.200.1/24'],
            'used_by' => [],
        ],
        $state->networks,
    ), JSON_THROW_ON_ERROR);
}

/** The recovery boundary under test; the cold build that follows it is the refresher's own. */
function rebuilderFor(
    StatePaths $paths,
    AtomicJsonStore $store,
    TopologySnapshotManifestStore $manifests,
): TopologySnapshotRebuilder {
    $host = new IncusHost(pool: 'orbit-e2e');

    return new TopologySnapshotRebuilder(
        $host,
        new IncusNetworkLifecycle($host),
        $manifests,
        $paths,
        new OperationLock($paths),
        new OperationId(str_repeat('a', 32)),
        rebuildIdentity(),
    );
}

/** @param list<TopologySnapshotGeneration> $recorded */
function rebuildAuthorization(
    RebuildHost $state,
    ?TopologySnapshotGeneration $generation = null,
    array $recorded = [],
): LegacyTopologySnapshotInventory {
    $generation ??= rebuildGeneration('old-generation');
    $recorded = $recorded === [] ? [$generation] : $recorded;
    $instances = [];
    foreach ($state->instances as $name) {
        $instances[$name] = [
            'remote' => 'local',
            'project' => 'default',
            'name' => $name,
            'pool' => 'orbit-e2e',
            'metadata' => $state->instanceMetadata[$name] ?? ['user.orbit.e2e.owner' => 'orbit-e2e'],
            'status' => 'STOPPED',
            'status_code' => 102,
            'network' => null,
            'mac' => null,
            'disks' => [],
        ];
    }
    $network = $state->networks === []
        ? null
        : [
            'remote' => 'local',
            'project' => 'default',
            'name' => $state->networks[0],
            'metadata' => $state->networkOwned ? ['user.orbit.e2e.owner' => 'orbit-e2e'] : [],
            'config' => $state->networkOwned
                ? ['user.orbit.e2e.owner' => 'orbit-e2e', 'ipv4.address' => '10.232.200.1/24']
                : ['ipv4.address' => '10.232.200.1/24'],
            'used_by' => [],
        ];

    return new LegacyTopologySnapshotInventory(
        ['remote' => 'local', 'project' => 'default', 'pool' => 'orbit-e2e', 'topology_snapshot_namespace' => 'live'],
        $generation->toArray(),
        array_map(static fn (TopologySnapshotGeneration $item): array => $item->toArray(), $recorded),
        $instances,
        array_fill_keys($state->instances, []),
        $network,
    );
}

describe('TopologySnapshotRebuilder', function () {
    beforeEach(function () {
        $container = new Container;
        $container->instance(ProcessFactory::class, new ProcessFactory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    });

    it('refuses ordinary rebuild when an exact configured resource is present', function (
        array $instances,
        array $networks,
        string $resource,
    ) {
        $state = new RebuildHost;
        $state->instances = $instances;
        $state->networks = $networks;
        fakeRebuildHost($state);

        $paths = new StatePaths(temporaryPath('orbit-rebuild-present-', 4));
        $store = new AtomicJsonStore($paths);
        $manifests = new TopologySnapshotManifestStore($store, $paths, new IncusHost(pool: 'orbit-e2e'));

        expect(fn () => rebuilderFor($paths, $store, $manifests)->teardown())
            ->toThrow(
                RuntimeException::class,
                "Topology snapshot resources are present: {$resource}. Use bin/e2e-topology-snapshot recover-legacy --main-sha=<sha>.",
            );
        expect($state->deleted)->toBe([]);
    })->with([
        'base VM without a manifest' => [
            ['orbit-e2e-live-topology-snapshot-gateway'],
            [],
            'orbit-e2e-live-topology-snapshot-gateway',
        ],
        'promotion copy without a manifest' => [
            ['orbit-e2e-live-topology-snapshot-app-dev-next'],
            [],
            'orbit-e2e-live-topology-snapshot-app-dev-next',
        ],
        'named network without a manifest' => [
            [],
            ['oe-l-topo-snap'],
            'oe-l-topo-snap',
        ],
    ]);

    it('deletes the topology snapshot VMs, the promotion leftovers, and the network itself', function () {
        $identity = rebuildIdentity();
        $state = new RebuildHost;
        $state->instances = [...$identity->instances(), $identity->instance('gateway').'-next'];
        $state->networks = [$identity->network()];
        fakeRebuildHost($state);

        $paths = new StatePaths(temporaryPath('orbit-rebuild-', 4));
        $store = new AtomicJsonStore($paths);
        $manifests = new TopologySnapshotManifestStore($store, $paths, new IncusHost(pool: 'orbit-e2e'));
        $manifests->promote(rebuildGeneration('old-generation'));
        $manifests->record(rebuildGeneration('old-generation'));
        $store->write('topology-snapshot/corrupt.json', ['schema' => 2, 'message' => 'stranded']);
        $phases = [];
        $teardown = rebuilderFor($paths, $store, $manifests)->recover(
            rebuildAuthorization($state),
            function (string $phase) use (&$phases): void {
                $phases[] = $phase;
            },
        );

        expect($teardown['instances_deleted'])
            ->toBe([
                'orbit-e2e-live-topology-snapshot-app-dev',
                'orbit-e2e-live-topology-snapshot-app-prod',
                'orbit-e2e-live-topology-snapshot-gateway',
                'orbit-e2e-live-topology-snapshot-gateway-next',
            ])
            ->and($teardown['networks_deleted'])
            ->toBe(['oe-l-topo-snap'])
            ->and($state->instances)
            ->toBe([])
            ->and($state->networks)
            ->toBe([])
            ->and($phases)
            ->toBe([
                'instances_pending',
                'instances_verified',
                'network_pending',
                'network_verified',
                'manifests_pending',
                'manifests_verified',
            ]);
    });

    it('recovers an authorized promotion copy with complete attempt identity', function () {
        $identity = rebuildIdentity();
        $copy = $identity->instance('gateway').'-next';
        $state = new RebuildHost;
        $state->instances = [...$identity->instances(), $copy];
        $state->instanceMetadata[$copy] = [
            'user.orbit.e2e.owner' => 'orbit-e2e',
            'user.orbit.e2e.operation' => str_repeat('a', 32),
            'user.orbit.e2e.issue' => 'ORB-92',
            'user.orbit.e2e.attempt' => str_repeat('b', 32),
        ];
        $state->networks = [$identity->network()];
        fakeRebuildHost($state);

        $paths = new StatePaths(temporaryPath('orbit-rebuild-copy-', 4));
        $store = new AtomicJsonStore($paths);
        $manifests = new TopologySnapshotManifestStore($store, $paths, new IncusHost(pool: 'orbit-e2e'));
        $generation = rebuildGeneration('old-generation');
        $manifests->promote($generation);
        $manifests->record($generation);

        $teardown = rebuilderFor($paths, $store, $manifests)->recover(
            rebuildAuthorization($state, $generation),
            static function (string $_phase): void {},
        );

        expect($teardown['instances_deleted'])
            ->toContain($copy)
            ->and($state->instances)
            ->toBe([]);
    });

    it('forgets every manifest and the corrupt marker so a cold build is permitted', function () {
        $identity = rebuildIdentity();
        $state = new RebuildHost;
        $state->instances = $identity->instances();
        $state->networks = [$identity->network()];
        fakeRebuildHost($state);

        $paths = new StatePaths(temporaryPath('orbit-rebuild-', 4));
        $store = new AtomicJsonStore($paths);
        $manifests = new TopologySnapshotManifestStore($store, $paths, new IncusHost(pool: 'orbit-e2e'));
        $old = rebuildGeneration('old-generation');
        $older = rebuildGeneration('older-generation');
        $manifests->promote($old);
        $manifests->record($old);
        $manifests->record($older);
        $store->write('topology-snapshot/corrupt.json', ['schema' => 2, 'message' => 'stranded']);
        $teardown = rebuilderFor($paths, $store, $manifests)->recover(
            rebuildAuthorization($state, $old, [$old, $older]),
            static function (string $_phase): void {},
        );

        expect($manifests->promoted())
            ->toBeNull()
            ->and($manifests->recorded())
            ->toBe([])
            ->and($store->read('topology-snapshot/corrupt.json'))
            ->toBeNull()
            ->and($teardown['instances_deleted'])
            ->toEqualCanonicalizing($identity->instances());
    });

    it('tears down schema 4 resources and forgets their legacy manifests', function () {
        $identity = rebuildIdentity();
        $state = new RebuildHost;
        $state->instances = $identity->instances();
        $state->networks = [$identity->network()];
        fakeRebuildHost($state);

        $paths = new StatePaths(temporaryPath('orbit-rebuild-legacy-', 4));
        $store = new AtomicJsonStore($paths);
        $manifests = new TopologySnapshotManifestStore($store, $paths, new IncusHost(pool: 'orbit-e2e'));
        $legacy = legacyRebuildGeneration('legacy-generation');
        $manifests->promote($legacy);
        $manifests->record($legacy);

        $teardown = rebuilderFor($paths, $store, $manifests)->recover(
            rebuildAuthorization($state, $legacy),
            static function (string $_phase): void {},
        );

        expect($teardown['instances_deleted'])
            ->toEqualCanonicalizing($identity->instances())
            ->and($teardown['networks_deleted'])
            ->toBe([$identity->network()])
            ->and($manifests->promoted())
            ->toBeNull()
            ->and($manifests->recorded())
            ->toBe([]);
    });

    it('refuses authorized recovery when the named network has no ownership metadata', function () {
        $identity = rebuildIdentity();
        $state = new RebuildHost;
        $state->networks = [$identity->network()];
        $state->networkOwned = false;
        fakeRebuildHost($state);

        $paths = new StatePaths(temporaryPath('orbit-rebuild-', 4));
        $store = new AtomicJsonStore($paths);
        $manifests = new TopologySnapshotManifestStore($store, $paths, new IncusHost(pool: 'orbit-e2e'));
        expect(fn () => rebuilderFor($paths, $store, $manifests)->recover(
            rebuildAuthorization($state),
            static function (string $_phase): void {},
        ))
            ->toThrow(RuntimeException::class, 'ownership does not match');
        expect($state->networks)->toBe(['oe-l-topo-snap']);
    });

    it('touches nothing when this checkout\'s topology snapshot is already gone', function () {
        $state = new RebuildHost;
        $state->instances = TopologySnapshotIdentity::primary()->instances();
        $state->networks = [TopologySnapshotIdentity::primary()->network()];
        fakeRebuildHost($state);

        $paths = new StatePaths(temporaryPath('orbit-rebuild-', 4));
        $store = new AtomicJsonStore($paths);
        $manifests = new TopologySnapshotManifestStore($store, $paths, new IncusHost(pool: 'orbit-e2e'));
        $teardown = rebuilderFor($paths, $store, $manifests)->teardown();

        expect($teardown['instances_deleted'])
            ->toBe([])
            ->and($teardown['networks_deleted'])
            ->toBe([])
            ->and($state->deleted)
            ->toBe([])
            ->and($state->instances)
            ->toBe(TopologySnapshotIdentity::primary()->instances());
    });

    it('resumes authorized teardown after every exact legacy resource is already absent', function () {
        $state = new RebuildHost;
        fakeRebuildHost($state);
        $paths = new StatePaths(temporaryPath('orbit-rebuild-resume-', 4));
        $store = new AtomicJsonStore($paths);
        $manifests = new TopologySnapshotManifestStore($store, $paths, new IncusHost(pool: 'orbit-e2e'));
        $authorizedState = new RebuildHost;
        $authorizedState->instances = TopologySnapshotIdentity::live()->instances();
        $authorizedState->networks = [TopologySnapshotIdentity::live()->network()];
        $phases = [];

        $teardown = rebuilderFor($paths, $store, $manifests)->recover(
            rebuildAuthorization($authorizedState),
            function (string $phase) use (&$phases): void {
                $phases[] = $phase;
            },
            instancesMayBeAbsent: true,
            networkMayBeAbsent: true,
            manifestsMayBeAbsent: true,
        );

        expect($teardown)->toBe(['instances_deleted' => [], 'networks_deleted' => []]);
        expect($phases)->toBe([
            'instances_pending',
            'instances_verified',
            'network_pending',
            'network_verified',
            'manifests_pending',
            'manifests_verified',
        ]);
    });

    it('refuses to delete a VM that is not harness-owned', function () {
        $identity = rebuildIdentity();
        $state = new RebuildHost;
        $state->instances = $identity->instances();
        $state->instanceMetadata[$identity->instance('gateway')] = ['user.orbit.e2e.owner' => 'someone-else'];
        fakeRebuildHost($state);

        $paths = new StatePaths(temporaryPath('orbit-rebuild-', 4));
        $store = new AtomicJsonStore($paths);
        $manifests = new TopologySnapshotManifestStore($store, $paths, new IncusHost(pool: 'orbit-e2e'));
        expect(fn () => rebuilderFor($paths, $store, $manifests)->recover(
            rebuildAuthorization($state),
            static function (string $_phase): void {},
        ))
            ->toThrow(RuntimeException::class, 'is not harness-owned')
            ->and($state->deleted)
            ->toBe([]);
    });

    it('refuses while a feature topology still holds a topology snapshot name', function () {
        $identity = rebuildIdentity();
        $state = new RebuildHost;
        $state->instances = $identity->instances();
        $state->instanceMetadata[$identity->instance('app-dev')] = [
            'user.orbit.e2e.owner' => 'orbit-e2e',
            'user.orbit.e2e.issue' => 'NCK-104',
        ];
        fakeRebuildHost($state);

        $paths = new StatePaths(temporaryPath('orbit-rebuild-', 4));
        $store = new AtomicJsonStore($paths);
        $manifests = new TopologySnapshotManifestStore($store, $paths, new IncusHost(pool: 'orbit-e2e'));
        expect(fn () => rebuilderFor($paths, $store, $manifests)->recover(
            rebuildAuthorization($state),
            static function (string $_phase): void {},
        ))
            ->toThrow(RuntimeException::class, 'belongs to issue NCK-104')
            ->and($state->deleted)
            ->toBe([]);
    });

    it('refuses before mutation when exact instance identity changes after authorization', function () {
        $identity = rebuildIdentity();
        $state = new RebuildHost;
        $state->instances = $identity->instances();
        fakeRebuildHost($state);
        $authorization = rebuildAuthorization($state);
        $state->instanceMetadata[$identity->instance('gateway')] = [
            'user.orbit.e2e.owner' => 'orbit-e2e',
            'user.orbit.e2e.operation' => str_repeat('f', 32),
        ];

        $paths = new StatePaths(temporaryPath('orbit-rebuild-changed-', 4));
        $store = new AtomicJsonStore($paths);
        $manifests = new TopologySnapshotManifestStore($store, $paths, new IncusHost(pool: 'orbit-e2e'));

        expect(fn () => rebuilderFor($paths, $store, $manifests)->recover(
            $authorization,
            static function (string $_phase): void {},
        ))
            ->toThrow(RuntimeException::class, 'instance inventory changed after authorization')
            ->and($state->deleted)
            ->toBe([]);
    });
});
