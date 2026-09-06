<?php

declare(strict_types=1);

use App\E2E\DiscoveryGuestPreparer;
use App\E2E\Git\GitRepository;
use App\E2E\HostCapacity;
use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\IssueState;
use App\E2E\PreparedStateFingerprint;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\TopologyAcquirer;
use App\E2E\TopologySnapshotManifestStore;
use App\E2E\TopologyVerifier;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\OperationId;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologyRequest;
use App\E2E\Value\TopologySnapshotGeneration;
use App\E2E\Value\TopologySnapshotIdentity;
use App\E2E\WorktreeSynchronizer;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Facade;

require_once __DIR__.'/Support/TopologyFixtures.php';

beforeEach(function () {
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
});

/** @return array{sourceRoot: string, worktree: string, branch: string, processes: ProcessFactory} */
function legacyAcquisitionWorktree(): array
{
    $sourceRoot = dirname(__DIR__, 5);
    $worktree = temporaryPath('orbit-legacy-acquisition-', 4);
    $branch = 'aux-4-legacy-acquisition-'.bin2hex(random_bytes(6));
    $processes = new ProcessFactory;

    expect(
        $processes->run(['git', '-C', $sourceRoot, 'worktree', 'add', '--detach', $worktree, 'HEAD'])->successful(),
    )->toBeTrue();
    expect($processes->run(['git', '-C', $worktree, 'switch', '-c', $branch])->successful())->toBeTrue();

    return compact('sourceRoot', 'worktree', 'branch', 'processes');
}

/** @param array{sourceRoot: string, worktree: string, branch: string, processes: ProcessFactory} $fixture */
function removeLegacyAcquisitionWorktree(array $fixture): void
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

function legacyAcquisitionGeneration(): TopologySnapshotGeneration
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

function topologyAcquirerWithLegacyGeneration(
    string $repositoryRoot,
    StatePaths $paths,
    TopologySnapshotManifestStore $manifests,
): TopologyAcquirer {
    $host = new IncusHost(pool: 'orbit-e2e');
    $operation = new OperationId(str_repeat('f', 32));

    return new TopologyAcquirer(
        $host,
        new IncusNetworkLifecycle($host),
        new PreparedStateFingerprint(new GitRepository($repositoryRoot)),
        $manifests,
        new WorktreeSynchronizer($host, $repositoryRoot, $operation),
        new TopologyVerifier($host, 1, 0),
        new DiscoveryGuestPreparer($host),
        new HostCapacity($host, 24),
        $paths,
        $operation,
        TopologySnapshotIdentity::primary(),
        $repositoryRoot,
        fn () => attemptId(),
    );
}

it('refuses acquisition from a schema 4 generation before creating an attempt', function () {
    $fixture = legacyAcquisitionWorktree();

    try {
        $paths = new StatePaths(temporaryPath('orbit-legacy-acquisition-state-', 4));
        $state = new AtomicJsonStore($paths);
        $manifests = new TopologySnapshotManifestStore($state, $paths, new IncusHost(pool: 'orbit-e2e'));
        $manifests->promote(legacyAcquisitionGeneration());
        $request = new TopologyRequest('AUX-4', $fixture['worktree']);

        expect(
            fn () => topologyAcquirerWithLegacyGeneration(
                $fixture['sourceRoot'],
                $paths,
                $manifests,
            )->acquire($request),
        )
            ->toThrow(RuntimeException::class, 'legacy; refresh it before acquisition')
            ->and(IssueState::forWorktree('AUX-4', $fixture['worktree'])->hasAttempt())
            ->toBeFalse();
    } finally {
        removeLegacyAcquisitionWorktree($fixture);
    }
});

it('constructs an extended discovery without adopting proof resources or sharing attempt identities', function (): void {
    $root = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-extended-acquisition-state-', 4));
    promoteDiscoveryGeneration($root, $paths);
    $worktree = pinnedFeatureWorktree($root, 'extended-acquisition');
    $planPath = $worktree.'/.loop/proof';
    mkdir($planPath, 0o700, true);
    file_put_contents($planPath.'/TST-123.json', json_encode([
        'setup' => [],
        'acceptance' => [[
            'id' => 'extended-ready',
            'node' => 'app-prod-2',
            'argv' => ['true'],
            'timeout_seconds' => 30,
        ]],
        'extension' => 'app-prod',
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)
        ."\n");
    $processes = new ProcessFactory;
    expect($processes->run(['git', '-C', $worktree, 'add', '.loop/proof/TST-123.json'])->successful())
        ->toBeTrue()
        ->and($processes->run(['git', '-C', $worktree, 'commit', '-q', '-m', 'Add extended plan'])->successful())
        ->toBeTrue();

    $recipe = TopologyRecipe::extendedAppProd();
    $discoveryTarget = featureTarget('TST-123', 'a', $recipe);
    $proofTarget = featureTarget('TST-123', 'b', $recipe);
    $events = [];
    fakePinnedWorktreeProcesses($discoveryTarget, $events);
    $host = new IncusHost(pool: 'default');
    $operation = new OperationId(str_repeat('f', 32));
    $manifests = new TopologySnapshotManifestStore(new AtomicJsonStore($paths), $paths, $host);
    $topology = new TopologyAcquirer(
        $host,
        new IncusNetworkLifecycle($host),
        new PreparedStateFingerprint(new GitRepository($root)),
        $manifests,
        new WorktreeSynchronizer($host, $root, $operation),
        new TopologyVerifier($host, 1, 0),
        new DiscoveryGuestPreparer($host),
        new HostCapacity($host, 24),
        $paths,
        $operation,
        TopologySnapshotIdentity::primary(),
        $root,
        fn () => attemptId('a'),
        new \App\E2E\TopologyConverger($host),
    )->acquire(new TopologyRequest('TST-123', $worktree));

    $commands = array_map(
        static fn (array $event): string => implode(' ', array_map(strval(...), $event)),
        $events,
    );
    expect($topology->purpose)
        ->toBe(AttemptPurpose::Discovery)
        ->and($topology->target->recipe->nodeKeys())
        ->toBe(['gateway', 'app-dev', 'app-prod', 'app-prod-2'])
        ->and(array_keys($topology->instances))
        ->toBe($topology->target->recipe->nodeKeys())
        ->and($topology->construction->extension?->value)
        ->toBe('app-prod')
        ->and($topology->construction->imageAlias)
        ->toBe(TopologyRecipe::BASE_IMAGE)
        ->and($topology->construction->imageFingerprint)
        ->toBe(str_repeat('b', 64))
        ->and($topology->construction->slot)
        ->toBe(2)
        ->and($topology->construction->nodes['app-prod-2']['incus_address'])
        ->toBe('10.232.2.13')
        ->and($topology->construction->nodes['app-prod-2']['wireguard_address'])
        ->toBe('10.44.0.4')
        ->and($topology->target->requireAttempt()->value)
        ->not->toBe($proofTarget->requireAttempt()->value)->and($topology->target->network())
        ->not->toBe($proofTarget->network())->and($topology->instances)
        ->not->toBe(array_combine($recipe->nodeKeys(), array_map(
            $proofTarget->instance(...),
            $recipe->nodeKeys(),
        )))->and(implode("\n", $commands))->toContain(
            'copy local:orbit-e2e-topology-snapshot-gateway/main-gateway',
            'copy local:orbit-e2e-topology-snapshot-app-dev/main-app-dev',
            'copy local:orbit-e2e-topology-snapshot-app-prod/main-app-prod',
            'init local:orbit-base-ubuntu-26.04-runtime',
            $discoveryTarget->instance('app-prod-2'),
        )
        ->not->toContain($proofTarget->network(), $proofTarget->instance('app-prod-2'));

    $state = IssueState::forWorktree('TST-123', $worktree);
    expect($state->requireTopology(AttemptPurpose::Discovery)->toArray())
        ->toBe($topology->toArray())
        ->and($state->hasAttempt(AttemptPurpose::Proof))
        ->toBeFalse();
});
