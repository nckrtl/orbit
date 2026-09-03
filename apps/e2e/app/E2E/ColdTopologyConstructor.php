<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\OperationLock;
use App\E2E\State\StatePaths;
use App\E2E\Value\ColdTopologyCleanupResult;
use App\E2E\Value\ColdTopologyPlan;
use App\E2E\Value\IncusInstance;
use App\E2E\Value\OperationId;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyPersistence;
use App\E2E\Value\TopologyTarget;
use App\Exceptions\E2E\ColdTopologyCleanupException;
use RuntimeException;
use Throwable;

/**
 * One exact resource transaction shared by persistent and disposable cold callers.
 *
 * @mago-expect lint:cyclomatic-complexity,kan-defect Exact construction and rollback keep every external-state branch at one boundary.
 */
final readonly class ColdTopologyConstructor
{
    /** @mago-expect lint:excessive-parameter-list Explicit infrastructure dependencies keep construction testable. */
    public function __construct(
        private IncusHost $host,
        private IncusNetworkLifecycle $networks,
        private WorktreeSynchronizer $synchronizer,
        private TopologyConverger $converger,
        private HostCapacity $capacity,
        private StatePaths $hostPaths,
    ) {}

    public function construct(ColdTopologyPlan $plan): SourceState
    {
        $this->preflight($plan);

        try {
            $this->createResources($plan);
            $instances = array_map($plan->target->instance(...), $plan->recipe->nodeKeys());
            $this->host->startAll($instances);
            $this->host->prepareClonedHostStates($instances);

            if ($plan->persistence === TopologyPersistence::Disposable) {
                $candidate = $this->synchronizer->syncCommit(
                    $plan->target,
                    $plan->sourceWorktree,
                    $plan->sourceSha,
                );
                $source = new SourceState(
                    $candidate->candidateSha,
                    $candidate->candidateSha,
                    operationId: $candidate->operationId,
                );
            } else {
                $source = $this->synchronizer->sync($plan->target, $plan->sourceWorktree);
            }
            if ($source->hostSha !== $plan->sourceSha || $source->guestSha !== $plan->sourceSha || $source->dirty) {
                throw new RuntimeException('Cold topology source is not the requested clean commit.');
            }

            $this->converger->converge($plan->target, $source, $plan->laravel);

            return $source;
        } catch (Throwable $constructionFailure) {
            $cleanup = $this->cleanup($plan->target, $plan->operation);
            if (! $cleanup->successful()) {
                throw new ColdTopologyCleanupException($cleanup, $constructionFailure);
            }

            throw $constructionFailure;
        }
    }

    public function cleanup(TopologyTarget $target, OperationId $operation): ColdTopologyCleanupResult
    {
        $instanceNames = array_map($target->instance(...), $target->recipe->nodeKeys());
        $removed = [];
        $absent = [];

        try {
            $instances = $this->host->instances($instanceNames);
            foreach ($instanceNames as $name) {
                if (! isset($instances[$name])) {
                    $absent[] = $name;
                }
            }
            foreach ($instances as $name => $instance) {
                $this->assertOperationResource($instance->metadata, $operation, $name);
            }
            $network = $this->host->network($target->network());
            if ($network === null) {
                $absent[] = $target->network();
            } else {
                $this->assertOperationResource($network->metadata, $operation, $network->name);
            }

            $running = array_keys(array_filter(
                $instances,
                static fn (IncusInstance $instance): bool => $instance->isRunning(),
            ));
            if ($running !== []) {
                $this->host->stopAll($running);
            }
            $deletions = array_values(array_filter(
                array_reverse($instanceNames),
                static fn (string $name): bool => isset($instances[$name]),
            ));
            if ($deletions !== []) {
                $this->host->deleteInstances($deletions);
                array_push($removed, ...$deletions);
            }
            if ($network !== null) {
                $this->networks->delete($network->name);
                $removed[] = $network->name;
            }
            if ($this->host->instances($instanceNames) !== [] || $this->host->network($target->network()) !== null) {
                throw new RuntimeException('A cold topology resource persisted after exact cleanup.');
            }

            return new ColdTopologyCleanupResult($removed, $absent, []);
        } catch (Throwable $cleanupFailure) {
            return new ColdTopologyCleanupResult($removed, $absent, [$cleanupFailure->getMessage()]);
        }
    }

    private function preflight(ColdTopologyPlan $plan): void
    {
        foreach ($plan->imageFingerprints as $image => $fingerprint) {
            if ($this->host->imageFingerprint($image) !== $fingerprint) {
                throw new RuntimeException("The cold topology base image [{$image}] changed before construction.");
            }
        }
        if ($this->host->network($plan->target->network()) !== null) {
            throw new RuntimeException('The cold topology network already exists.');
        }
        $instanceNames = array_map($plan->target->instance(...), $plan->recipe->nodeKeys());
        if ($this->host->instances($instanceNames) !== []) {
            throw new RuntimeException('A cold topology VM already exists.');
        }
    }

    private function createResources(ColdTopologyPlan $plan): int
    {
        $creation = new OperationLock($this->hostPaths);
        if (! $creation->acquire(OrphanNetworkSweep::CREATION_LOCK, $plan->operation, timeoutSeconds: 600)) {
            throw new RuntimeException('Another topology creation holds the host.');
        }

        try {
            $slot = $plan->persistence === TopologyPersistence::Disposable
                ? $this->capacity->reserveSlot(count($plan->recipe->nodes))
                : $plan->fixedSlot ?? throw new RuntimeException('Persistent cold topology slot is absent.');
            $lastAddress = max(array_map(
                static fn (\App\E2E\Value\TopologyNode $node): int => $node->address,
                $plan->recipe->nodes,
            ));
            $this->networks->create($plan->target->network(), $slot, $plan->metadata, $lastAddress);
            $vms = [];
            foreach ($plan->recipe->nodes as $node) {
                $vms[$node->key] = [
                    'image' => $node->image,
                    'name' => $plan->target->instance($node->key),
                    'network' => $plan->target->network(),
                    'role' => $node->key,
                    'address' => $node->address,
                    'topology' => $plan->target->network(),
                    'slot' => $slot,
                    'metadata' => $plan->metadata,
                ];
            }
            $this->host->initVms($vms);

            return $slot;
        } finally {
            $creation->release();
        }
    }

    /** @param array<string, string> $metadata */
    private function assertOperationResource(array $metadata, OperationId $operation, string $resource): void
    {
        if (($metadata['user.orbit.e2e.owner'] ?? null) !== 'orbit-e2e') {
            throw new RuntimeException("Cold topology resource {$resource} ownership identity does not match.");
        }
        if (($metadata['user.orbit.e2e.operation'] ?? null) !== $operation->value) {
            throw new RuntimeException("Cold topology resource {$resource} belongs to another operation.");
        }
    }
}
