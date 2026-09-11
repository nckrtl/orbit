<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\SnapshotScenarioRunner;
use App\E2E\State\OperationLock;
use App\E2E\State\ScenarioRunStore;
use App\E2E\State\StatePaths;
use App\E2E\TopologySnapshotAvailability;
use App\E2E\TopologySnapshotManifestStore;
use App\E2E\Value\OperationId;
use App\E2E\Value\ScenarioStatus;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologySnapshotIdentity;
use App\E2E\Value\TopologyTarget;

/** @return array<string, mixed> */
function stableSnapshotScenarioPromotion(
    StatePaths $paths,
    TopologySnapshotManifestStore $manifests,
    IncusHost $host,
    OperationId $operation,
): array {
    $lock = new OperationLock($paths);
    if (! $lock->acquire('standby-generation', $operation, exclusive: false, timeoutSeconds: 3600)) {
        throw new RuntimeException('Unable to observe the promoted topology snapshot generation.');
    }

    try {
        $generation = $manifests->promoted()
            ?? throw new RuntimeException('The promoted topology snapshot generation is absent.');
        new TopologySnapshotAvailability($host, TopologySnapshotIdentity::primary())->assertAvailable($generation);

        return $generation->toArray();
    } finally {
        $lock->release();
    }
}

it('snapshot-scenario-lifecycle prepares the exact candidate before bounded exercise', function (): void {
    $paths = $this->app->make(StatePaths::class);
    $manifests = $this->app->make(TopologySnapshotManifestStore::class);
    $host = $this->app->make(IncusHost::class);
    $operation = $this->app->make(OperationId::class);
    $before = stableSnapshotScenarioPromotion($paths, $manifests, $host, $operation);

    $result = $this->app->make(SnapshotScenarioRunner::class)->executeFromEnvironment('snapshot-lifecycle');
    $attempt = $this->app->make(ScenarioRunStore::class)
        ->attempt($result->run, $result->scenario, $result->attempt);

    expect($result->status)->toBe(ScenarioStatus::Passed);
    expect(array_column($result->actions, 'phase'))->toBe(['setup', 'exercise', 'assertion']);
    expect($attempt['construction_inputs']['candidate_sync']['candidate_sha'] ?? null)->toBe($result->candidate);
    expect($attempt['construction_inputs']['source_generation'] ?? null)->toBe($before);
    expect($result->cleanup['remaining'] ?? null)->toBe([]);
    expect(stableSnapshotScenarioPromotion($paths, $manifests, $host, $operation))->toBe($before);
})->skip(fn (): bool => getenv('ORBIT_SCENARIO_ID') !== 'snapshot-lifecycle');

it('snapshot-scenario-isolation starts without a prior attempt filesystem mutation', function (): void {
    $paths = $this->app->make(StatePaths::class);
    $manifests = $this->app->make(TopologySnapshotManifestStore::class);
    $host = $this->app->make(IncusHost::class);
    $operation = $this->app->make(OperationId::class);
    $before = stableSnapshotScenarioPromotion($paths, $manifests, $host, $operation);

    $result = $this->app->make(SnapshotScenarioRunner::class)->executeFromEnvironment('snapshot-isolation');

    expect($result->status)->toBe(ScenarioStatus::Passed);
    expect($result->actions[1]['evidence'] ?? null)
        ->toBe('fresh clone did not contain the prior attempt marker');
    expect($result->cleanup['remaining'] ?? null)->toBe([]);
    expect(stableSnapshotScenarioPromotion($paths, $manifests, $host, $operation))->toBe($before);
})->skip(fn (): bool => getenv('ORBIT_SCENARIO_ID') !== 'snapshot-isolation');

it('snapshot-scenario-extension records and removes the declared physical Node', function (): void {
    $paths = $this->app->make(StatePaths::class);
    $manifests = $this->app->make(TopologySnapshotManifestStore::class);
    $host = $this->app->make(IncusHost::class);
    $operation = $this->app->make(OperationId::class);
    $before = stableSnapshotScenarioPromotion($paths, $manifests, $host, $operation);

    $result = $this->app->make(SnapshotScenarioRunner::class)->executeFromEnvironment('snapshot-extension');
    $attempt = $this->app->make(ScenarioRunStore::class)
        ->attempt($result->run, $result->scenario, $result->attempt);
    $target = TopologyTarget::disposableScenario(
        $result->run,
        $result->scenario,
        $result->attempt,
        TopologyRecipe::extendedAppProd(),
    );
    $construction = $attempt['construction_inputs']['construction'] ?? [];

    expect($result->status)->toBe(ScenarioStatus::Passed);
    expect($construction['extension'] ?? null)->toBe('app-prod');
    expect($construction['image_alias'] ?? null)->toBe(TopologyRecipe::BASE_IMAGE);
    expect($construction['image_fingerprint'] ?? null)->toMatch('/\A[a-f0-9]{64}\z/');
    expect($construction['nodes']['app-prod-2']['instance'] ?? null)->toBe($target->instance('app-prod-2'));
    expect($result->cleanup['removed'] ?? null)->toContain($target->instance('app-prod-2'));
    expect($result->cleanup['remaining'] ?? null)->toBe([]);
    expect(stableSnapshotScenarioPromotion($paths, $manifests, $host, $operation))->toBe($before);
})->skip(fn (): bool => getenv('ORBIT_SCENARIO_ID') !== 'snapshot-extension');
