<?php

declare(strict_types=1);

use App\E2E\DiscoveryGuestPreparer;
use App\E2E\Git\GitRepository;
use App\E2E\HostCapacity;
use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\IssueState;
use App\E2E\PreparedStateFingerprint;
use App\E2E\PromotedTopologySnapshotResolver;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\TopologyAcquirer;
use App\E2E\TopologyConverger;
use App\E2E\TopologySnapshotAvailability;
use App\E2E\TopologySnapshotManifestStore;
use App\E2E\TopologySnapshotReplacementStore;
use App\E2E\TopologyVerifier;
use App\E2E\Value\AttemptId;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\OperationId;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologyRequest;
use App\E2E\Value\TopologySnapshotGeneration;
use App\E2E\Value\TopologySnapshotIdentity;
use App\E2E\Value\TopologySnapshotReplacementInstallation;
use App\E2E\WorktreeSynchronizer;
use Illuminate\Container\Container;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Factory as ProcessFactory;
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

function preparedTopologyAcquirer(
    string $repositoryRoot,
    StatePaths $paths,
    IncusHost $host,
    OperationId $operation,
): TopologyAcquirer {
    $manifests = new TopologySnapshotManifestStore(new AtomicJsonStore($paths), $paths, $host);

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

function acquirerConflictingReplacementInstallation(
    TopologySnapshotGeneration $requested,
): TopologySnapshotReplacementInstallation {
    $oldValue = $requested->toArray();
    $oldValue['id'] = 'replacement-old-generation';
    $old = TopologySnapshotGeneration::fromArray($oldValue);
    $new = new TopologySnapshotGeneration(
        'replacement-new-generation',
        str_repeat('6', 40),
        ['gateway' => 'main-replacement-gateway', 'app-dev' => 'main-replacement-app-dev', 'app-prod' => 'main-replacement-app-prod'],
        str_repeat('7', 64),
        $old->baseImageFingerprint,
        $old->laravel,
        str_repeat('8', 64),
        2,
        $old->coldEpoch,
        $old->baseImageAlias,
        TopologyProfile::NAME,
        TopologyProfile::ROLES,
        TopologyProfile::CHECKOUT_ROLES,
        $old->id,
    );

    return new TopologySnapshotReplacementInstallation(
        'AUX-231',
        new AttemptId(str_repeat('a', 32)),
        new AttemptId(str_repeat('b', 32)),
        new OperationId(str_repeat('c', 32)),
        str_repeat('d', 40),
        str_repeat('e', 40),
        str_repeat('f', 40),
        $new->mainSha,
        str_repeat('a', 64),
        str_repeat('b', 64),
        $old,
        $new,
        $new->baseImageAlias,
        $new->baseImageFingerprint,
        'oe-replacement',
        ['gateway' => 'replacement-gateway', 'app-dev' => 'replacement-app-dev', 'app-prod' => 'replacement-app-prod'],
        ['gateway' => 'snapshot-gateway', 'app-dev' => 'snapshot-app-dev', 'app-prod' => 'snapshot-app-prod'],
        ['gateway' => 'snapshot-gateway-next', 'app-dev' => 'snapshot-app-dev-next', 'app-prod' => 'snapshot-app-prod-next'],
        ['gateway' => 'snapshot-gateway-old', 'app-dev' => 'snapshot-app-dev-old', 'app-prod' => 'snapshot-app-prod-old'],
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

it('refuses acquisition before Incus mutation when an active replacement conflicts with the promoted generation', function (): void {
    $root = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-replacement-acquisition-state-', 4));
    promoteDiscoveryGeneration($root, $paths);
    $worktree = pinnedFeatureWorktree($root, 'replacement-acquisition');
    $host = new IncusHost(pool: 'default');
    $state = new AtomicJsonStore($paths);
    $manifests = new TopologySnapshotManifestStore($state, $paths, $host);
    $generation = $manifests->promoted();
    assert($generation !== null);
    $replacements = new TopologySnapshotReplacementStore($state);
    $replacements->start(acquirerConflictingReplacementInstallation($generation), '2026-09-10T10:00:00Z');
    $events = [];
    fakePinnedWorktreeProcesses(featureTarget('TST-123'), $events);
    $operation = new OperationId(str_repeat('f', 32));
    $request = new TopologyRequest('TST-123', $worktree);
    $acquirer = new TopologyAcquirer(
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
        fn () => attemptId(),
        snapshotResolver: new PromotedTopologySnapshotResolver(
            new PreparedStateFingerprint(new GitRepository($root)),
            $manifests,
            new TopologySnapshotAvailability(
                $host,
                TopologySnapshotIdentity::primary(),
                $replacements,
            ),
        ),
    );

    expect(fn () => $acquirer->acquire($request))
        ->toThrow(RuntimeException::class, 'replacement recovery is incomplete')
        ->and($events)->toBeEmpty()
        ->and(IssueState::forWorktree('TST-123', $worktree)->hasAttempt())->toBeFalse();
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
    $leaseAtNetworkCreation = null;
    fakePinnedWorktreeProcesses(
        $discoveryTarget,
        $events,
        observe: static function (array $command) use ($worktree, &$leaseAtNetworkCreation): void {
            if (($command[3] ?? null) !== 'network' || ($command[4] ?? null) !== 'create') {
                return;
            }
            $leaseAtNetworkCreation = json_decode(
                (string) file_get_contents($worktree.'/.e2e/'.IssueState::ATTEMPT),
                true,
                8,
                JSON_THROW_ON_ERROR,
            );
        },
    );
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
        new TopologyConverger($host),
    )->acquire(new TopologyRequest('TST-123', $worktree));

    $commands = array_map(
        static fn (array $event): string => implode(' ', array_map(strval(...), $event)),
        $events,
    );
    expect($topology->purpose)
        ->toBe(AttemptPurpose::Discovery)
        ->and($leaseAtNetworkCreation['extension'] ?? null)
        ->toBe('app-prod')
        ->and($leaseAtNetworkCreation['attempt_id'] ?? null)
        ->toBe($discoveryTarget->requireAttempt()->value)
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
            $discoveryTarget->instance('app-prod').' -- bash -s -- 10.44.0.10 10.44.0.10:51820',
        )
        ->not->toContain(
            $proofTarget->network(),
            $proofTarget->instance('app-prod-2'),
            $discoveryTarget->instance('app-prod-2').' -- /usr/local/bin/retarget-vpn.sh',
            $discoveryTarget->instance('app-prod-2').' -- bash -s --',
        );

    $state = IssueState::forWorktree('TST-123', $worktree);
    expect($state->requireTopology(AttemptPurpose::Discovery)->toArray())
        ->toBe($topology->toArray())
        ->and($state->attempt(AttemptPurpose::Discovery)['extension'])
        ->toBe('app-prod')
        ->and($state->hasAttempt(AttemptPurpose::Proof))
        ->toBeFalse();
});

it('rolls back only its acquired clones without publishing readiness when Gateway identity fails', function (int $exitCode, string $output): void {
    $root = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-identity-acquisition-', 4));
    promoteDiscoveryGeneration($root, $paths);
    $worktree = pinnedFeatureWorktree($root, 'identity-failure');
    $target = featureTarget('TST-123');
    $events = [];
    fakePinnedWorktreeProcesses($target, $events, guestOverride: static function (array $guest) use ($exitCode, $output) {
        if (in_array('/home/orbit/orbit/apps/e2e/resources/guest/retarget-gateway.php', $guest, true)) {
            return Process::result($output, '', $exitCode);
        }

        return null;
    });
    $host = new IncusHost(pool: 'default');
    $operation = new OperationId(str_repeat('f', 32));
    $manifests = new TopologySnapshotManifestStore(new AtomicJsonStore($paths), $paths, $host);
    $acquirer = new TopologyAcquirer(
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
        fn () => attemptId(),
    );

    expect(fn () => $acquirer->acquire(new TopologyRequest('TST-123', $worktree)))
        ->toThrow(RuntimeException::class, 'Topology acquisition failed: Gateway clone identity preparation');

    $state = IssueState::forWorktree('TST-123', $worktree);
    expect($state->hasAttempt())->toBeFalse();
    expect(file_exists($worktree.'/.e2e/topology.json'))->toBeFalse();
    $deletions = array_values(array_filter($events, static fn (array $command): bool => ($command[3] ?? null) === 'delete'));
    expect(array_column($deletions, 4))->toEqualCanonicalizing(array_map(
        static fn (string $role): string => 'local:'.$target->instance($role), TopologyProfile::ROLES,
    ));
    $networks = array_values(array_filter($events, static fn (array $command): bool => array_slice($command, 3, 2) === ['network', 'delete']));
    expect(array_column($networks, 5))->toBe(['local:'.$target->network()]);
    $commands = implode("\n", array_map(static fn (array $event): string => implode(' ', $event), $events));
    expect($commands)->not->toContain(' -- bash -s --', '/usr/local/bin/verify-topology.sh', 'converge-gateway.sh');
})->with([
    'failed publication' => [65, ''],
    'malformed publication' => [0, '{"app-dev":"10.44.0.10:51820"}'],
]);

it('prepares the mounted Gateway schema before standard discovery readiness without converging product state', function (): void {
    $root = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-schema-acquisition-state-', 4));
    promoteDiscoveryGeneration($root, $paths);
    $worktree = pinnedFeatureWorktree($root, 'schema-acquisition');
    $target = featureTarget('TST-123');
    $events = [];
    fakePinnedWorktreeProcesses($target, $events);
    $host = new IncusHost(pool: 'default');
    $operation = new OperationId(str_repeat('f', 32));

    $topology = preparedTopologyAcquirer($root, $paths, $host, $operation)
        ->acquire(new TopologyRequest('TST-123', $worktree));

    $commands = array_map(
        static fn (array $event): string => implode(' ', array_map(strval(...), $event)),
        $events,
    );
    $sourceMarker = array_find_key($commands, static fn (string $command): bool => str_contains(
        $command,
        WorktreeSynchronizer::SOURCE_STATE_MARKER,
    ));
    $migration = array_find_key($commands, static fn (string $command): bool => str_contains(
        $command,
        $target->instance('gateway').' -- runuser -u orbit -- env -C /home/orbit '
            .'HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit DB_DATABASE=/home/orbit/.orbit/gateway.sqlite '
            .'env -C /home/orbit/orbit/apps/gateway ORBIT_GATEWAY_CHECKOUT=/home/orbit/orbit/apps/gateway '
            .'DB_DATABASE=/home/orbit/.orbit/gateway.sqlite php artisan migrate --force --no-interaction',
    ));
    $verification = array_find_key($commands, static fn (string $command): bool => str_contains(
        $command,
        ' -- /usr/local/bin/verify-topology.sh ',
    ));

    expect($topology->source->mounted)
        ->toBeTrue()
        ->and($sourceMarker)
        ->toBeInt()
        ->and($migration)
        ->toBeInt()
        ->toBeGreaterThan($sourceMarker)
        ->and($verification)
        ->toBeInt()
        ->toBeGreaterThan($migration)
        ->and(implode("\n", $commands))
        ->not->toContain(' -- /usr/local/bin/converge-gateway.sh ', 'orbit:bootstrap', 'converge-sample-app.sh create-resources', 'converge-sample-app.sh reproject');
});

it('retains the old successful source after failed sync and accepts a corrected retry at the same HEAD', function (): void {
    $root = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-schema-sync-state-', 4));
    promoteDiscoveryGeneration($root, $paths);
    $worktree = pinnedFeatureWorktree($root, 'schema-sync');
    $target = featureTarget('TST-123');
    $events = [];
    $failMigration = false;
    $migrationCalls = 0;
    fakePinnedWorktreeProcesses(
        $target,
        $events,
        guestOverride: static function (array $guest) use (&$failMigration, &$migrationCalls) {
            if (in_array('artisan', $guest, true) && in_array('migrate', $guest, true)) {
                $migrationCalls++;

                return $failMigration
                    ? Process::result('private migration output', 'private migration error', 73)
                    : Process::result();
            }

            return null;
        },
    );
    $host = new IncusHost(pool: 'default');
    $operation = new OperationId(str_repeat('f', 32));
    $acquirer = preparedTopologyAcquirer($root, $paths, $host, $operation);
    $request = new TopologyRequest('TST-123', $worktree);
    $ready = $acquirer->acquire($request);
    expect($ready->source->dirty)->toBeFalse()->and($ready->source->treeHash)->toBeNull();
    $readyRecord = $ready->toArray();
    $readyAttempt = $ready->attempt->value;
    $migrationDirectory = $worktree.'/apps/gateway/database/migrations';
    if (! is_dir($migrationDirectory)) {
        mkdir($migrationDirectory, 0700, true);
    }
    $migration = $migrationDirectory.'/2099_01_01_000000_schema_failure.php';
    file_put_contents($migration, "<?php\nthrow new RuntimeException('fixture');\n");
    $failMigration = true;

    expect(fn () => $acquirer->sync($request))
        ->toThrow(RuntimeException::class, 'Gateway schema preparation failed with exit code 73.');

    $state = IssueState::forWorktree('TST-123', $worktree);
    expect($state->attemptId(AttemptPurpose::Discovery)->value)
        ->toBe($readyAttempt)
        ->and($state->requireTopology(AttemptPurpose::Discovery)->toArray())
        ->toBe($readyRecord)
        ->and(fn () => $acquirer->verify($request))
        ->toThrow(
            RuntimeException::class,
            'The mounted source differs from the last successful readiness record; run topology sync.',
        );

    file_put_contents($migration, "<?php\n// corrected fixture\n");
    $failMigration = false;
    $retried = $acquirer->sync($request);
    $repeated = $acquirer->sync($request);

    expect($retried->attempt->value)
        ->toBe($readyAttempt)
        ->and($retried->source->dirty)
        ->toBeTrue()
        ->and($retried->source->overlayPaths)
        ->toContain('apps/gateway/database/migrations/2099_01_01_000000_schema_failure.php')
        ->and($repeated->source->toArray())
        ->toBe($retried->source->toArray())
        ->and($migrationCalls)
        ->toBe(4);
});

it('rolls back its exact attempt without publishing readiness when Gateway migration fails', function (): void {
    $root = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-schema-failure-state-', 4));
    promoteDiscoveryGeneration($root, $paths);
    $worktree = pinnedFeatureWorktree($root, 'schema-failure');
    $target = featureTarget('TST-123');
    $events = [];
    fakePinnedWorktreeProcesses(
        $target,
        $events,
        guestOverride: static fn (array $guest) => in_array('artisan', $guest, true) && in_array('migrate', $guest, true)
            ? Process::result('private migration output', 'private migration error', 73)
            : null,
    );
    $host = new IncusHost(pool: 'default');
    $operation = new OperationId(str_repeat('f', 32));

    expect(fn () => preparedTopologyAcquirer($root, $paths, $host, $operation)->acquire(
        new TopologyRequest('TST-123', $worktree),
    ))
        ->toThrow(
            RuntimeException::class,
            'Topology acquisition failed: Gateway schema preparation failed with exit code 73.',
        );

    $state = IssueState::forWorktree('TST-123', $worktree);
    expect($state->hasAttempt())
        ->toBeFalse()
        ->and(file_exists($worktree.'/.e2e/topology.json'))
        ->toBeFalse();
    $deletions = array_values(array_filter($events, static fn (array $command): bool => ($command[3] ?? null) === 'delete'));
    expect(array_column($deletions, 4))->toEqualCanonicalizing(array_map(
        static fn (string $role): string => 'local:'.$target->instance($role),
        TopologyProfile::ROLES,
    ));
});

it('retains the exact lease and resources when migration-failure cleanup ownership drifts', function (): void {
    $root = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-schema-refusal-state-', 4));
    promoteDiscoveryGeneration($root, $paths);
    $worktree = pinnedFeatureWorktree($root, 'schema-refusal');
    $target = featureTarget('TST-123');
    $events = [];
    $migrationFailed = false;
    $rollbackInventories = 0;
    fakePinnedWorktreeProcesses(
        $target,
        $events,
        guestOverride: static function (array $guest) use (&$migrationFailed) {
            if (in_array('artisan', $guest, true) && in_array('migrate', $guest, true)) {
                $migrationFailed = true;

                return Process::result('', '', 73);
            }

            return null;
        },
        inventoryOverride: static function (array $command, ProcessResult $result) use (
            &$migrationFailed,
            &$rollbackInventories,
            $target,
        ): ?ProcessResult {
            if (! $migrationFailed || ($command[3] ?? null) !== 'list' || ($command[4] ?? null) !== 'local:') {
                return null;
            }
            $rollbackInventories++;
            if ($rollbackInventories < 2) {
                return null;
            }
            $inventory = json_decode($result->output(), true, 512, JSON_THROW_ON_ERROR);
            foreach ($inventory as &$instance) {
                if (($instance['name'] ?? null) === $target->instance('gateway')) {
                    $instance['config']['user.orbit.e2e.operation'] = str_repeat('0', 32);
                }
            }
            unset($instance);

            return Process::result(json_encode($inventory, JSON_THROW_ON_ERROR));
        },
    );
    $host = new IncusHost(pool: 'default');
    $operation = new OperationId(str_repeat('f', 32));

    expect(fn () => preparedTopologyAcquirer($root, $paths, $host, $operation)->acquire(
        new TopologyRequest('TST-123', $worktree),
    ))
        ->toThrow(RuntimeException::class, 'rollback was refused');

    $state = IssueState::forWorktree('TST-123', $worktree);
    expect($state->hasAttempt(AttemptPurpose::Discovery))
        ->toBeTrue()
        ->and($state->attemptId(AttemptPurpose::Discovery)->value)
        ->toBe($target->requireAttempt()->value)
        ->and(file_exists($worktree.'/.e2e/topology.json'))
        ->toBeFalse()
        ->and(array_filter($events, static fn (array $command): bool => ($command[3] ?? null) === 'delete'))
        ->toBeEmpty();
});

it('uses the acquired generation for discovery sync after main and promotion change while proof requires freshness', function (): void {
    $root = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-flow-acquisition-', 4));
    promoteDiscoveryGeneration($root, $paths);
    $worktree = pinnedFeatureWorktree($root, 'discovery-flow');
    $processes = new ProcessFactory;
    $manifest = $root.'/apps/e2e/resources/prepared-state.json';
    $data = json_decode((string) file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
    $data['cold_epoch'] = 'ubuntu-26.04-amd64-v99';
    file_put_contents($manifest, json_encode($data, JSON_THROW_ON_ERROR));
    expect(
        $processes->run(['git', '-C', $root, 'commit', '-am', 'Main changes cold contract'])->successful(),
    )->toBeTrue();
    expect($processes->run(['git', '-C', $root, 'branch', '-f', 'main', 'HEAD'])->successful())->toBeTrue();
    $events = [];
    fakePinnedWorktreeProcesses(featureTarget('TST-123'), $events);
    $host = new IncusHost(pool: 'default');
    $operation = new OperationId(str_repeat('f', 32));
    $manifests = new TopologySnapshotManifestStore(new AtomicJsonStore($paths), $paths, $host);
    $acquirer = new TopologyAcquirer(
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
        fn () => attemptId(),
    );
    $request = new TopologyRequest('TST-123', $worktree);
    mkdir($worktree.'/.loop', 0700, true);
    file_put_contents($worktree.'/.loop/flow.json', '{"schema":1,"flow":"proof"}');
    expect(fn () => $acquirer->acquire($request))->toThrow(RuntimeException::class, 'snapshot is stale');
    expect($events)->toBeEmpty();
    unlink($worktree.'/.loop/flow.json');

    $topology = $acquirer->acquire($request);
    $featureManifest = $worktree.'/apps/e2e/resources/prepared-state.json';
    $originalFeatureManifest = (string) file_get_contents($featureManifest);
    $changedFeatureManifest = json_decode($originalFeatureManifest, true, 512, JSON_THROW_ON_ERROR);
    $changedFeatureManifest['cold_epoch'] = 'ubuntu-26.04-amd64-v98';
    file_put_contents($featureManifest, json_encode($changedFeatureManifest, JSON_THROW_ON_ERROR));
    expect($processes->run(['git', '-C', $worktree, 'commit', '-am', 'Change feature cold contract'])->successful())
        ->toBeTrue();
    expect(fn () => $acquirer->sync($request))
        ->toThrow(RuntimeException::class, 'The feature prepared state changes the cold base contract.');
    file_put_contents($featureManifest, $originalFeatureManifest);
    expect($processes->run(['git', '-C', $worktree, 'commit', '-am', 'Restore feature cold contract'])->successful())
        ->toBeTrue();
    $manifests->promote(legacyAcquisitionGeneration());
    $synced = $acquirer->sync($request);

    expect($topology->purpose)->toBe(AttemptPurpose::Discovery);
    expect($synced->generation->id)->toBe($topology->generation->id);
    expect(IssueState::forWorktree('TST-123', $worktree)->hasAttempt(AttemptPurpose::Proof))->toBeFalse();
    expect(file_exists($worktree.'/.loop/proof/TST-123.json'))->toBeFalse();
});
