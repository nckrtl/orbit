<?php

declare(strict_types=1);

use App\E2E\ColdTopologyConstructor;
use App\E2E\Git\GitRepository;
use App\E2E\HostCapacity;
use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\PreparedStateFingerprint;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\OperationLock;
use App\E2E\State\SecretRedactor;
use App\E2E\State\StatePaths;
use App\E2E\TopologyConverger;
use App\E2E\TopologySnapshotManifestStore;
use App\E2E\TopologySnapshotPromotionStore;
use App\E2E\TopologySnapshotReplacementInstaller;
use App\E2E\TopologySnapshotReplacementStore;
use App\E2E\TopologyVerifier;
use App\E2E\Value\AttemptId;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\CapturedProof;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\OperationId;
use App\E2E\Value\ProofInputManifest;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyConstructionInputs;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologyRequest;
use App\E2E\Value\TopologySnapshotGeneration;
use App\E2E\Value\TopologySnapshotIdentity;
use App\E2E\Value\TopologySnapshotReplacementInstallation;
use App\E2E\Value\TopologySnapshotReplacementResult;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationReport;
use App\E2E\WorktreeSynchronizer;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
});

/** @return array{capture:CapturedProof,installation:TopologySnapshotReplacementInstallation,candidate:string,artifact:string,merge:string,main:string} */
function interruptedReplacementFixture(): array
{
    $issue = 'TST-231';
    $proofAttempt = new AttemptId(str_repeat('a', 32));
    $replacementAttempt = new AttemptId(str_repeat('b', 32));
    $operation = new OperationId(str_repeat('c', 32));
    $candidate = str_repeat('1', 40);
    $artifact = str_repeat('2', 40);
    $merge = str_repeat('3', 40);
    $main = str_repeat('4', 40);
    $alias = TopologyRecipe::BASE_IMAGE;
    $imageFingerprint = str_repeat('5', 64);
    $laravel = new LaravelRelease('v13.0.0', str_repeat('6', 40));
    $proofGeneration = replacementGeneration(
        'proof-generation',
        $candidate,
        str_repeat('7', 64),
        $imageFingerprint,
        $alias,
        $laravel,
    );
    $proofTarget = TopologyTarget::feature($issue, $proofAttempt, TopologyRecipe::registered($alias));
    $construction = TopologyConstructionInputs::forSnapshotReplacement(
        $proofTarget,
        2,
        $alias,
        $imageFingerprint,
    );
    $verification = new VerificationReport(true, [
        'replacement' => verificationProbeFixture(),
    ]);
    $topology = new FeatureTopology(
        $construction,
        AttemptPurpose::Proof,
        $proofGeneration,
        new SourceState($candidate, $candidate, operationId: $operation->value),
        $verification,
    );
    $manifest = new ProofInputManifest(
        1,
        $candidate,
        $candidate,
        [],
        [],
        '.loop/proof/TST-231.json',
        [],
        $construction,
        null,
        [
            'static_classification' => true,
            'proof_contract' => true,
            'checkout_literals' => true,
            'observed_processes' => true,
            'observed_paths' => true,
            'pcov_cleanup' => true,
        ],
    );
    $proof = [
        'status' => 'proved',
        'issue' => $issue,
        'attempt_id' => $proofAttempt->value,
        'candidate_sha' => $candidate,
        'plan_sha256' => str_repeat('8', 64),
        'manifest_sha256' => $manifest->fingerprint(),
    ];
    $capture = new CapturedProof(
        $issue,
        $proofAttempt,
        $candidate,
        str_repeat('8', 64),
        $manifest->fingerprint(),
        $proof,
        $topology,
        $manifest->toArray(),
        '2026-09-10T12:00:00Z',
    );
    $old = replacementGeneration(
        'old-generation',
        str_repeat('9', 40),
        str_repeat('a', 64),
        str_repeat('b', 64),
        $alias,
        $laravel,
    );
    $new = replacementGeneration(
        'new-generation',
        $main,
        str_repeat('c', 64),
        $imageFingerprint,
        $alias,
        $laravel,
        $old->id,
    );
    $temporary = TopologyTarget::disposableCold(
        $issue,
        $replacementAttempt,
        TopologyRecipe::registered($alias),
    );
    $canonical = TopologyTarget::topologySnapshot();
    $temporaryInstances = [];
    $canonicalInstances = [];
    $nextInstances = [];
    $oldInstances = [];
    foreach (TopologyProfile::ROLES as $role) {
        $temporaryInstances[$role] = $temporary->instance($role);
        $canonicalInstances[$role] = $canonical->instance($role);
        $nextInstances[$role] = $canonicalInstances[$role].'-next';
        $oldInstances[$role] = $canonicalInstances[$role].'-old';
    }
    $installation = new TopologySnapshotReplacementInstallation(
        $issue,
        $proofAttempt,
        $replacementAttempt,
        $operation,
        $candidate,
        $artifact,
        $merge,
        $main,
        $capture->fingerprint(),
        $manifest->fingerprint(),
        $old,
        $new,
        $alias,
        $imageFingerprint,
        $temporary->network(),
        $temporaryInstances,
        $canonicalInstances,
        $nextInstances,
        $oldInstances,
    );

    return compact('capture', 'installation', 'candidate', 'artifact', 'merge', 'main');
}

function replacementGeneration(
    string $id,
    string $sha,
    string $prepared,
    string $imageFingerprint,
    string $alias,
    LaravelRelease $laravel,
    ?string $previous = null,
): TopologySnapshotGeneration {
    return new TopologySnapshotGeneration(
        $id,
        $sha,
        array_fill_keys(TopologyProfile::ROLES, 'main-'.$id),
        $prepared,
        $imageFingerprint,
        $laravel,
        str_repeat('d', 64),
        2,
        'cold-v1',
        $alias,
        TopologyProfile::NAME,
        TopologyProfile::ROLES,
        TopologyProfile::CHECKOUT_ROLES,
        $previous,
        TopologyProfile::ASSIGNMENTS,
    );
}

function interruptedReplacementInstaller(
    StatePaths $paths,
    TopologySnapshotReplacementStore $store,
): TopologySnapshotReplacementInstaller {
    $host = new IncusHost(pool: 'orbit-e2e');
    $operation = new OperationId(str_repeat('e', 32));
    $constructor = new ColdTopologyConstructor(
        $host,
        new IncusNetworkLifecycle($host),
        new WorktreeSynchronizer($host, dirname(__DIR__, 5), $operation),
        new ReflectionClass(TopologyConverger::class)->newInstanceWithoutConstructor(),
        new HostCapacity($host, 9),
        $paths,
    );

    return new TopologySnapshotReplacementInstaller(
        $host,
        $constructor,
        new ReflectionClass(PreparedStateFingerprint::class)->newInstanceWithoutConstructor(),
        new ReflectionClass(TopologyVerifier::class)->newInstanceWithoutConstructor(),
        new TopologySnapshotManifestStore(new AtomicJsonStore($paths), $paths, $host),
        new TopologySnapshotPromotionStore(new AtomicJsonStore($paths)),
        $store,
        new OperationLock($paths),
        new OperationLock($paths),
        new ReflectionClass(GitRepository::class)->newInstanceWithoutConstructor(),
        $operation,
        TopologySnapshotIdentity::primary(),
        dirname(__DIR__, 5),
        new SecretRedactor,
    );
}

it('cleans and archives interrupted preparation before allowing a fresh retry', function (string $phase): void {
    $fixture = interruptedReplacementFixture();
    $paths = new StatePaths(temporaryPath('replacement-installer-', 4));
    $store = new TopologySnapshotReplacementStore(new AtomicJsonStore($paths));
    $manifests = new TopologySnapshotManifestStore(new AtomicJsonStore($paths), $paths, new IncusHost);
    $manifests->record($fixture['installation']->oldGeneration);
    $manifests->promote($fixture['installation']->oldGeneration);
    $manifests->record($fixture['installation']->newGeneration);
    $recovery = $store->start($fixture['installation'], '2026-09-10T12:00:00Z')
        ->withPhase('construction_pending', '2026-09-10T12:00:01Z');
    if ($phase === 'staging_pending') {
        $recovery = $recovery
            ->withPhase('construction_verified', '2026-09-10T12:00:02Z')
            ->withPhase('staging_pending', '2026-09-10T12:00:03Z');
    }
    $store->advance($recovery);
    Process::fake(function (PendingProcess $process) {
        $command = $process->command;
        assert(is_array($command));
        if (
            $command === ['incus', '--project', 'default', 'list', 'local:', '--format=json']
            || $command === ['incus', '--project', 'default', 'network', 'list', 'local:', '--format=json']
        ) {
            return Process::result('[]');
        }

        throw new RuntimeException('Unexpected mutation: '.implode(' ', $command));
    });

    $result = interruptedReplacementInstaller($paths, $store)->install(
        new TopologyRequest('TST-231', dirname(__DIR__, 5)),
        $fixture['capture'],
        $fixture['candidate'],
        $fixture['artifact'],
        $fixture['merge'],
        $fixture['main'],
    );

    expect($result->state)
        ->toBe('failed')
        ->and($result->phase)
        ->toBe('abandoned')
        ->and($store->active())
        ->toBeNull()
        ->and($store->archived($fixture['capture']->attempt)?->phase)
        ->toBe('abandoned')
        ->and($manifests->promoted()?->id)
        ->toBe($fixture['installation']->oldGeneration->id)
        ->and(array_map(static fn (TopologySnapshotGeneration $generation): string => $generation->id, $manifests->recorded()))
        ->not->toContain($fixture['installation']->newGeneration->id);
})->with(['construction_pending', 'staging_pending']);

it('records clean reconstruction lineage when resuming after manifest promotion', function (): void {
    $fixture = interruptedReplacementFixture();
    $paths = new StatePaths(temporaryPath('replacement-lineage-', 4));
    $atomic = new AtomicJsonStore($paths);
    $store = new TopologySnapshotReplacementStore($atomic);
    $manifests = new TopologySnapshotManifestStore($atomic, $paths, new IncusHost);
    $manifests->record($fixture['installation']->oldGeneration);
    $manifests->promote($fixture['installation']->oldGeneration);
    $manifests->record($fixture['installation']->newGeneration);
    $manifests->promote($fixture['installation']->newGeneration);

    $recovery = $store->start($fixture['installation'], '2026-09-10T12:00:00Z');
    foreach ([
        'construction_pending',
        'construction_verified',
        'staging_pending',
        'staging_verified',
        'swap_pending',
        'swap_in_progress',
    ] as $phase) {
        $recovery = $recovery->withPhase($phase, '2026-09-10T12:00:01Z');
    }
    foreach (TopologyProfile::ROLES as $role) {
        $recovery = $recovery->withOldRename($role)->withNewRename($role);
    }
    $recovery = $recovery
        ->withPhase('manifest_pending', '2026-09-10T12:00:02Z')
        ->withGenerationRecorded();
    $store->advance($recovery);

    $installer = interruptedReplacementInstaller($paths, $store);
    $recordCommitted = new ReflectionMethod($installer, 'recordCommittedManifest');
    $resumed = $recordCommitted->invoke($installer, $recovery);
    $promotions = new TopologySnapshotPromotionStore($atomic);
    $lineage = $promotions->find($fixture['installation']->newGeneration->id);

    expect($resumed->phase)
        ->toBe('manifest_promoted')
        ->and($lineage)
        ->not->toBeNull()
        ->and($lineage['promotion_path'] ?? null)
        ->toBe('clean-reconstruction')
        ->and($lineage['proved_sha'] ?? null)
        ->toBe($fixture['candidate'])
        ->and($lineage['merged_sha'] ?? null)
        ->toBe($fixture['main']);

    $recordedAt = $lineage['recorded_at'] ?? null;
    $recordCommitted->invoke($installer, $resumed);

    expect($promotions->find($fixture['installation']->newGeneration->id)['recorded_at'] ?? null)
        ->toBe($recordedAt);
});

it('returns an idempotent abandonment result when no installation exists', function (): void {
    $fixture = interruptedReplacementFixture();
    $paths = new StatePaths(temporaryPath('replacement-abandon-', 4));
    $store = new TopologySnapshotReplacementStore(new AtomicJsonStore($paths));

    $result = interruptedReplacementInstaller($paths, $store)->abandon(
        new TopologyRequest('TST-231', dirname(__DIR__, 5)),
        $fixture['capture'],
    );

    expect($result->state)->toBe('abandoned')
        ->and($result->successful())->toBeFalse()
        ->and($result->error)->toBeNull();
});

it('accepts only complete installed and actionable failed results', function (): void {
    $installed = new TopologySnapshotReplacementResult(
        'installed',
        str_repeat('a', 32),
        'new-generation',
        'complete',
    );

    expect($installed->successful())->toBeTrue()
        ->and($installed->toArray()['generation_id'])->toBe('new-generation')
        ->and(fn () => new TopologySnapshotReplacementResult(
            'failed',
            str_repeat('a', 32),
            null,
            'validation',
        ))->toThrow(InvalidArgumentException::class);
});
