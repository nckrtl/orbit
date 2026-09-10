<?php

declare(strict_types=1);

use App\E2E\Git\GitRepository;
use App\E2E\IncusHost;
use App\E2E\PreparedStateFingerprint;
use App\E2E\PromotedTopologySnapshotResolver;
use App\E2E\StaleTopologySnapshotManifest;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\TopologySnapshotAvailability;
use App\E2E\TopologySnapshotManifestStore;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologySnapshotGeneration;
use App\E2E\Value\TopologySnapshotIdentity;
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

/** @param array{id?: string, preparedFingerprint?: string, structuralFingerprint?: string, manifestSchema?: int} $changes */
function promotedResolverGeneration(string $repositoryRoot, array $changes = []): TopologySnapshotGeneration
{
    $mainSha = new GitRepository($repositoryRoot)->commit();
    $structural = new PreparedStateFingerprint(new GitRepository($repositoryRoot))->forCommit($mainSha);
    $laravel = topologyPromotedLaravel();
    $prepared = new PreparedStateFingerprint(new GitRepository($repositoryRoot))->withLaravel($structural, $laravel);
    $preparedFingerprint = $changes['preparedFingerprint'] ?? $prepared->value;
    $manifestSchema = $changes['manifestSchema'] ?? TopologySnapshotGeneration::SCHEMA;

    return new TopologySnapshotGeneration(
        $changes['id'] ?? substr($mainSha, 0, 12).'-'.substr($preparedFingerprint, 0, 12),
        $mainSha,
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        $preparedFingerprint,
        str_repeat('b', 64),
        $laravel,
        $changes['structuralFingerprint'] ?? $structural->value,
        $manifestSchema === TopologySnapshotGeneration::LEGACY_SCHEMA ? 1 : $structural->manifest['schema'],
        $structural->manifest['cold_epoch'],
        $structural->manifest['base_image_alias'],
        $structural->manifest['topology']['profile'],
        $structural->manifest['topology']['roles'],
        $structural->manifest['topology']['checkout_roles'],
        topologyAssignments: $manifestSchema === TopologySnapshotGeneration::LEGACY_SCHEMA
            ? null
            : TopologyProfile::ASSIGNMENTS,
        manifestSchema: $manifestSchema,
    );
}

function promotedResolverStore(StatePaths $paths, IncusHost $host): TopologySnapshotManifestStore
{
    return new TopologySnapshotManifestStore(new AtomicJsonStore($paths), $paths, $host);
}

function promotedResolver(
    string $repositoryRoot,
    TopologySnapshotManifestStore $store,
    IncusHost $host,
): PromotedTopologySnapshotResolver {
    return new PromotedTopologySnapshotResolver(
        new PreparedStateFingerprint(new GitRepository($repositoryRoot)),
        $store,
        new TopologySnapshotAvailability($host, TopologySnapshotIdentity::primary()),
    );
}

function fakePromotedResolverHost(bool $snapshotsAvailable = true): void
{
    $realProcess = new ProcessFactory;
    Process::fake(function (PendingProcess $process) use ($realProcess, $snapshotsAvailable) {
        $command = $process->command;
        assert(is_array($command), 'Commands use argument arrays.');
        if (($command[0] ?? null) === 'git') {
            return $realProcess
                ->path((string) ($process->path ?: getcwd()))
                ->input($process->input)
                ->run($command);
        }
        if (($command[3] ?? null) === 'list' && ($command[4] ?? null) === 'local:') {
            return Process::result(topologySnapshotVmInventoryJson());
        }
        if (($command[3] ?? null) === 'snapshot' && ($command[4] ?? null) === 'list') {
            $instance = preg_replace('/\A[^:]+:/', '', (string) ($command[5] ?? ''));

            return Process::result(topologySnapshotSnapshotInventoryJson($instance, $snapshotsAvailable));
        }

        return Process::result();
    });
}

it('returns the complete available promoted generation', function (): void {
    $root = preparedTopologyRepository();
    $worktree = pinnedFeatureWorktree($root, 'resolver-available');
    $paths = new StatePaths(temporaryPath('orbit-promoted-resolver-', 4));
    $host = new IncusHost(pool: 'default');
    $store = promotedResolverStore($paths, $host);
    $generation = promotedResolverGeneration($root);
    $store->promote($generation);
    fakePromotedResolverHost();

    $resolved = promotedResolver($root, $store, $host)->resolve($worktree);

    expect($resolved->toArray())->toBe($generation->toArray());
});

it('refuses a missing promoted generation', function (): void {
    $root = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-promoted-resolver-', 4));
    $host = new IncusHost(pool: 'default');

    expect(fn () => promotedResolver($root, promotedResolverStore($paths, $host), $host)->resolve($root))
        ->toThrow(RuntimeException::class, 'No promoted topology snapshot generation is available.');
});

it('refuses a legacy promoted generation', function (): void {
    $root = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-promoted-resolver-', 4));
    $host = new IncusHost(pool: 'default');
    $store = promotedResolverStore($paths, $host);
    $store->promote(promotedResolverGeneration($root, [
        'id' => 'legacy-generation',
        'manifestSchema' => TopologySnapshotGeneration::LEGACY_SCHEMA,
    ]));

    expect(fn () => promotedResolver($root, $store, $host)->resolve($root))
        ->toThrow(RuntimeException::class, 'legacy; refresh it before acquisition');
});

it('refuses a generation whose ID does not bind its source and prepared state', function (): void {
    $root = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-promoted-resolver-', 4));
    $host = new IncusHost(pool: 'default');
    $store = promotedResolverStore($paths, $host);
    $store->promote(promotedResolverGeneration($root, ['id' => 'wrong-generation']));

    expect(fn () => promotedResolver($root, $store, $host)->resolve($root))
        ->toThrow(RuntimeException::class, 'fingerprint is stale or corrupt');
});

it('refuses structural fingerprint drift', function (): void {
    $root = preparedTopologyRepository();
    $worktree = pinnedFeatureWorktree($root, 'resolver-structural');
    $paths = new StatePaths(temporaryPath('orbit-promoted-resolver-', 4));
    $host = new IncusHost(pool: 'default');
    $store = promotedResolverStore($paths, $host);
    $store->promote(promotedResolverGeneration($root, ['structuralFingerprint' => str_repeat('a', 64)]));

    expect(fn () => promotedResolver($root, $store, $host)->resolve($worktree))
        ->toThrow(RuntimeException::class, 'snapshot is stale');
});

it('refuses prepared fingerprint drift', function (): void {
    $root = preparedTopologyRepository();
    $worktree = pinnedFeatureWorktree($root, 'resolver-prepared');
    $paths = new StatePaths(temporaryPath('orbit-promoted-resolver-', 4));
    $host = new IncusHost(pool: 'default');
    $store = promotedResolverStore($paths, $host);
    $store->promote(promotedResolverGeneration($root, ['preparedFingerprint' => str_repeat('a', 64)]));

    expect(fn () => promotedResolver($root, $store, $host)->resolve($worktree))
        ->toThrow(RuntimeException::class, 'snapshot is stale');
});

it('refuses feature cold-base changes', function (string $key, string $value): void {
    $root = preparedTopologyRepository();
    $worktree = pinnedFeatureWorktree($root, 'resolver-cold-base-'.$key);
    $paths = new StatePaths(temporaryPath('orbit-promoted-resolver-', 4));
    $host = new IncusHost(pool: 'default');
    $store = promotedResolverStore($paths, $host);
    $store->promote(promotedResolverGeneration($root));
    $manifestPath = $worktree.'/apps/e2e/resources/prepared-state.json';
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    $manifest[$key] = $value;
    file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
    $processes = new ProcessFactory;
    expect($processes->run(['git', '-C', $worktree, 'commit', '-am', 'Change cold base'])->successful())->toBeTrue();

    expect(fn () => promotedResolver($root, $store, $host)->resolve($worktree))
        ->toThrow(RuntimeException::class, 'The feature prepared state changes the cold base contract.');
})->with([
    'cold epoch' => ['cold_epoch', 'ubuntu-26.04-amd64-v99'],
    'base image alias' => ['base_image_alias', 'orbit-base-ubuntu-26.04-other'],
]);

it('refuses a promoted generation whose owned snapshots are unavailable', function (): void {
    $root = preparedTopologyRepository();
    $worktree = pinnedFeatureWorktree($root, 'resolver-unavailable');
    $paths = new StatePaths(temporaryPath('orbit-promoted-resolver-', 4));
    $host = new IncusHost(pool: 'default');
    $store = promotedResolverStore($paths, $host);
    $store->promote(promotedResolverGeneration($root));
    fakePromotedResolverHost(snapshotsAvailable: false);

    expect(fn () => promotedResolver($root, $store, $host)->resolve($worktree))
        ->toThrow(StaleTopologySnapshotManifest::class, 'bin/e2e-topology-snapshot rebuild');
});
