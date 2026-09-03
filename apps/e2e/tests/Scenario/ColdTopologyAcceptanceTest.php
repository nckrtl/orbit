<?php

declare(strict_types=1);

use App\E2E\ColdTopologyConstructor;
use App\E2E\IncusHost;
use App\E2E\LaravelReleaseResolver;
use App\E2E\PreparedStateFingerprint;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\TopologySnapshotManifestStore;
use App\E2E\TopologyVerifier;
use App\E2E\Value\AttemptId;
use App\E2E\Value\ColdTopologyPlan;
use App\E2E\Value\OperationId;
use App\E2E\Value\TopologyPersistence;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationMode;

it('constructs and releases the four-Node cold acceptance topology', function () {
    $candidate = getenv('ORBIT_SCENARIO_CANDIDATE_SHA');
    $repository = getenv('ORBIT_SCENARIO_REPOSITORY');
    $primary = getenv('ORBIT_SCENARIO_PRIMARY_ROOT');
    if (
        ! is_string($candidate)
        || preg_match('/\A[a-f0-9]{40}\z/D', $candidate) !== 1
        || ! is_string($repository)
        || ! str_starts_with($repository, '/')
        || ! is_string($primary)
        || ! str_starts_with($primary, '/')
    ) {
        throw new RuntimeException('Run this flow through bin/e2e-scenarios cold CANDIDATE_SHA.');
    }

    $this->app->instance(StatePaths::class, StatePaths::forPrimary($primary));
    foreach ([
        AtomicJsonStore::class,
        ColdTopologyConstructor::class,
        TopologySnapshotManifestStore::class,
    ] as $service) {
        $this->app->forgetInstance($service);
    }

    $host = $this->app->make(IncusHost::class);
    $constructor = $this->app->make(ColdTopologyConstructor::class);
    $manifests = $this->app->make(TopologySnapshotManifestStore::class);
    $operation = $this->app->make(OperationId::class);
    $laravel = $this->app->make(LaravelReleaseResolver::class)->resolve('>=13.0.0');
    $fingerprint = $this->app->make(PreparedStateFingerprint::class)->forCommit($candidate, $laravel);
    $image = $fingerprint->manifest['base_image_alias'] ?? null;
    if ($image !== TopologyRecipe::BASE_IMAGE) {
        throw new RuntimeException('The faithful cold flow requires the Ubuntu 26.04 runtime base alias.');
    }

    $recipe = TopologyRecipe::coldAcceptance($image);
    $attempt = AttemptId::generate();
    $target = TopologyTarget::disposableCold('SCN-1', $attempt, $recipe);
    $beforePromotion = $manifests->promoted()?->toArray();
    $source = null;
    $cleanup = null;

    try {
        $source = $constructor->construct(new ColdTopologyPlan(
            $target,
            $recipe,
            $repository,
            $candidate,
            [$image => $host->imageFingerprint($image)],
            $laravel,
            $operation,
            [
                'user.orbit.e2e.issue' => 'SCN-1',
                'user.orbit.e2e.attempt' => $attempt->value,
                'user.orbit.e2e.operation' => $operation->value,
                'user.orbit.e2e.recipe' => $recipe->id,
            ],
            TopologyPersistence::Disposable,
        ));

        $verification = $this->app->make(TopologyVerifier::class)->verify(
            $target,
            VerificationMode::Proof,
            $source,
        );
        $instances = $host->instances(array_map($target->instance(...), $recipe->nodeKeys()));

        expect($source->hostSha)->toBe($candidate);
        expect($source->guestSha)->toBe($candidate);
        expect($verification->passed)->toBeTrue();
        expect(array_keys($instances))->toBe(array_map($target->instance(...), $recipe->nodeKeys()));
        expect($instances[$target->instance('operator')]->metadata['user.orbit.e2e.attempt'] ?? null)
            ->toBe($attempt->value);
        expect($instances[$target->instance('extra')]->metadata['user.orbit.e2e.attempt'] ?? null)
            ->toBe($attempt->value);
        expect($manifests->promoted()?->toArray())->toBe($beforePromotion);
    } finally {
        $cleanup = $constructor->cleanup($target, $operation);
    }

    expect($cleanup->successful())->toBeTrue();
    expect($cleanup->refused)->toBe([]);
    expect($host->instances(array_map($target->instance(...), $recipe->nodeKeys())))->toBe([]);
    expect($host->network($target->network()))->toBeNull();
    expect($manifests->promoted()?->toArray())->toBe($beforePromotion);
});
