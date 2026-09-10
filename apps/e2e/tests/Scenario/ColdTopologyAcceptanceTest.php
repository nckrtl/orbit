<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\ScenarioColdExecutor;
use App\E2E\State\OperationLock;
use App\E2E\State\StatePaths;
use App\E2E\TopologySnapshotAvailability;
use App\E2E\TopologySnapshotManifestStore;
use App\E2E\Value\OperationId;
use App\E2E\Value\ScenarioStatus;
use App\E2E\Value\TopologySnapshotIdentity;

/** @return array<string, mixed>|null */
function stableScenarioPromotion(
    StatePaths $paths,
    TopologySnapshotManifestStore $manifests,
    IncusHost $host,
    OperationId $operation,
): ?array {
    $lock = new OperationLock($paths);
    if (! $lock->acquire('standby-generation', $operation, exclusive: false, timeoutSeconds: 3600)) {
        throw new RuntimeException('Unable to observe the promoted topology snapshot generation.');
    }

    try {
        $generation = $manifests->promoted();
        if ($generation !== null) {
            new TopologySnapshotAvailability($host, TopologySnapshotIdentity::primary())
                ->assertAvailable($generation);
        }

        return $generation?->toArray();
    } finally {
        $lock->release();
    }
}

function assertScenarioDidNotMutatePromotion(?array $before, ?array $after): void
{
    if ($before !== $after) {
        throw new RuntimeException('The promoted topology snapshot changed during the cold scenario.');
    }
}

it('cold-scenario-suite constructs and releases the four-Node topology', function (): void {
    $paths = $this->app->make(StatePaths::class);
    $manifests = $this->app->make(TopologySnapshotManifestStore::class);
    $host = $this->app->make(IncusHost::class);
    $operation = $this->app->make(OperationId::class);
    $before = stableScenarioPromotion($paths, $manifests, $host, $operation);

    $result = $this->app->make(ScenarioColdExecutor::class)->executeFromEnvironment('cold-four-node');

    expect($result->status)->toBe(ScenarioStatus::Passed);
    expect($result->verification['passed'] ?? null)->toBeTrue();
    expect($result->cleanup['remaining'] ?? null)->toBe([]);
    expect($result->definition['observes_php'] ?? null)->toBeFalse();
    assertScenarioDidNotMutatePromotion(
        $before,
        stableScenarioPromotion($paths, $manifests, $host, $operation),
    );
});

it('cold-scenario-suite-cleanup releases exact resources after construction failure', function (): void {
    $paths = $this->app->make(StatePaths::class);
    $manifests = $this->app->make(TopologySnapshotManifestStore::class);
    $host = $this->app->make(IncusHost::class);
    $operation = $this->app->make(OperationId::class);
    $before = stableScenarioPromotion($paths, $manifests, $host, $operation);

    $result = $this->app->make(ScenarioColdExecutor::class)
        ->executeFromEnvironment('cold-construction-cleanup');

    expect($result->status)->toBe(ScenarioStatus::Passed);
    expect($result->actions[0]['name'] ?? null)->toBe('injected-source-failure');
    expect($result->cleanup['remaining'] ?? null)->toBe([]);
    expect($result->cleanup['recovery_command'] ?? null)
        ->toBe("bin/e2e-scenarios cleanup {$result->run->value} {$result->scenario->value} {$result->attempt->value}");
    assertScenarioDidNotMutatePromotion(
        $before,
        stableScenarioPromotion($paths, $manifests, $host, $operation),
    );
});
