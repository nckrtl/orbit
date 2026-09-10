<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\OperationLock;
use App\E2E\State\StatePaths;
use App\E2E\Value\ColdTopologyCleanupResult;
use App\E2E\Value\ColdTopologyPlan;
use App\E2E\Value\OperationId;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyNode;
use App\E2E\Value\TopologyTarget;
use App\Exceptions\E2E\ColdTopologyCleanupException;
use Closure;
use RuntimeException;
use Throwable;

/**
 * One exact resource transaction shared by persistent and disposable cold callers.
 */
final readonly class ColdTopologyConstructor
{
    public function __construct(
        private IncusHost $host,
        private IncusNetworkLifecycle $networks,
        private WorktreeSynchronizer $synchronizer,
        private TopologyConverger $converger,
        private HostCapacity $capacity,
        private StatePaths $hostPaths,
    ) {}

    /**
     * @param  (Closure(string, float, float, bool, ?string): void)|null  $observePhase
     * @param  (Closure(ColdTopologyCleanupResult, float, float): void)|null  $observeCleanup
     */
    public function construct(
        ColdTopologyPlan $plan,
        ?Closure $observePhase = null,
        ?Closure $observeCleanup = null,
    ): SourceState {
        $this->phase('preflight', fn () => $this->preflight($plan), $observePhase);

        try {
            $this->phase('create-resources', fn () => $this->createResources($plan), $observePhase);
            $instances = array_map($plan->target->instance(...), $plan->target->recipe->nodeKeys());
            $this->phase('start-instances', fn () => $this->host->startAll($instances), $observePhase);
            $this->phase('prepare-host-state', fn () => $this->host->prepareClonedHostStates($instances), $observePhase);

            $source = $this->phase('synchronize-source', function () use ($plan): SourceState {
                if ($plan->isDisposable()) {
                    $candidate = $this->synchronizer->syncCommit(
                        $plan->target,
                        $plan->sourceWorktree,
                        $plan->sourceSha,
                    );

                    return new SourceState(
                        $candidate->candidateSha,
                        $candidate->candidateSha,
                        operationId: $candidate->operationId,
                    );
                }

                return $this->synchronizer->sync($plan->target, $plan->sourceWorktree);
            }, $observePhase);
            if ($source->hostSha !== $plan->sourceSha || $source->guestSha !== $plan->sourceSha || $source->dirty) {
                throw new RuntimeException('Cold topology source is not the requested clean commit.');
            }

            $this->phase('converge', fn () => $this->converger->converge($plan->target, $source, $plan->laravel), $observePhase);

            return $source;
        } catch (Throwable $constructionFailure) {
            $cleanupStarted = microtime(true);
            $cleanup = $this->cleanup($plan->target, $plan->operation);
            $observeCleanup?->__invoke($cleanup, $cleanupStarted, microtime(true));
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

            foreach (array_reverse($instanceNames) as $name) {
                $instance = $instances[$name] ?? null;
                if ($instance === null) {
                    continue;
                }
                if ($instance->isRunning()) {
                    $this->host->stop($name);
                }
                $this->host->deleteInstance($name);
                $removed[] = $name;
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
            $expected = [...$instanceNames, $target->network()];
            $remaining = array_values(array_diff($expected, $removed, $absent));

            return new ColdTopologyCleanupResult($removed, $absent, [$cleanupFailure->getMessage()], $remaining);
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
        $instanceNames = array_map($plan->target->instance(...), $plan->target->recipe->nodeKeys());
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
            $slot = $plan->isDisposable()
                ? $this->capacity->reserveSlot(count($plan->target->recipe->nodes))
                : $plan->fixedSlot ?? throw new RuntimeException('Persistent cold topology slot is absent.');
            $lastAddress = max(array_map(
                static fn (TopologyNode $node): int => $node->address,
                $plan->target->recipe->nodes,
            ));
            $this->networks->create($plan->target->network(), $slot, $plan->metadata, $lastAddress);
            $vms = [];
            foreach ($plan->target->recipe->nodes as $node) {
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

    /**
     * @template T
     *
     * @param  Closure(): T  $action
     * @param  (Closure(string, float, float, bool, ?string): void)|null  $observer
     * @return T
     */
    private function phase(string $name, Closure $action, ?Closure $observer): mixed
    {
        $started = microtime(true);

        try {
            $result = $action();
            $observer?->__invoke($name, $started, microtime(true), true, null);

            return $result;
        } catch (Throwable $exception) {
            $observer?->__invoke($name, $started, microtime(true), false, $exception->getMessage());

            throw $exception;
        }
    }
}
