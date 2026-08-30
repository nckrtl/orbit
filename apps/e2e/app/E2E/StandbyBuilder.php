<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\AtomicJsonStore;
use App\E2E\Value\IncusInstance;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\OperationId;
use App\E2E\Value\PreparedFingerprint;
use App\E2E\Value\SourceState;
use App\E2E\Value\StandbyIdentity;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyTarget;
use RuntimeException;
use Throwable;

/**
 * Build the standby topology from the generic base image when no promoted
 * generation exists. The standby names are fixed, so cleanup after a failed
 * build needs no intent record: every standby resource stamped with this
 * operation is deleted.
 *
 * @mago-expect lint:excessive-parameter-list,cyclomatic-complexity,kan-defect Cold construction keeps its exact resource transaction at one boundary.
 */
final readonly class StandbyBuilder
{
    public function __construct(
        private IncusHost $host,
        private IncusNetworkLifecycle $networks,
        private WorktreeSynchronizer $synchronizer,
        private TopologyConverger $converger,
        private TopologyVerifier $verifier,
        private StandbyManifestStore $manifests,
        private AtomicJsonStore $state,
        private string $mainWorktree,
        private StandbyIdentity $identity,
    ) {}

    public function build(
        string $mainSha,
        PreparedFingerprint $fingerprint,
        string $baseImageFingerprint,
        LaravelRelease $laravel,
        bool $allowCold,
        OperationId $operation,
    ): SourceState {
        if (! $allowCold) {
            throw new RuntimeException('Cold standby construction requires explicit permission.');
        }
        if ($this->state->read('standby/corrupt.json') !== null) {
            throw new RuntimeException(
                'Cold standby construction is blocked until explicit recovery clears corrupt state.',
            );
        }
        if ($this->manifests->promoted() !== null) {
            throw new RuntimeException('Cold standby construction is refused while a promoted generation exists.');
        }

        $alias = $fingerprint->manifest['base_image_alias'] ?? null;
        if (! is_string($alias) || $alias === '') {
            throw new RuntimeException('The prepared fingerprint has no base image alias.');
        }

        $target = TopologyTarget::standby($this->identity);
        if ($this->host->imageFingerprint($alias) !== $baseImageFingerprint) {
            throw new RuntimeException('The base image alias fingerprint changed before cold construction.');
        }
        if ($this->host->network($target->network()) !== null) {
            throw new RuntimeException('The standby network already exists without a promoted generation.');
        }
        $instanceNames = array_map($target->instance(...), TopologyProfile::ROLES);
        if ($this->host->instances($instanceNames) !== []) {
            throw new RuntimeException('A standby VM already exists without a promoted generation.');
        }

        try {
            $resourceMetadata = ['user.orbit.e2e.operation' => $operation->value];
            $this->networks->create($target->network(), $this->identity->slot, $resourceMetadata);
            $vms = [];
            foreach (TopologyProfile::ROLES as $role) {
                $vms[$role] = [
                    'image' => $alias,
                    'name' => $target->instance($role),
                    'network' => $target->network(),
                    'role' => $role,
                    'topology' => $target->network(),
                    'slot' => $this->identity->slot,
                    'metadata' => $resourceMetadata,
                ];
            }
            $this->host->initVms($vms);
            $this->host->startAll($instanceNames);
            $this->host->prepareClonedHostStates($instanceNames);

            $source = $this->synchronizer->sync($target, $this->mainWorktree);
            if ($source->hostSha !== $mainSha || $source->guestSha !== $mainSha || $source->dirty) {
                throw new RuntimeException('Cold standby source is not clean merged main.');
            }

            $this->converger->converge($target, $source, $laravel);

            return $source;
        } catch (Throwable $exception) {
            if (! $this->cleanupCold($operation)) {
                throw new RuntimeException(
                    'Cold standby cleanup failed; explicit recovery is required.',
                    previous: $exception,
                );
            }

            throw $exception;
        }
    }

    /**
     * Delete every standby resource this operation created and prove absence.
     * A resource of another operation blocks the cleanup and marks the standby corrupt.
     */
    public function cleanupCold(OperationId $operation): bool
    {
        $target = TopologyTarget::standby($this->identity);
        $instanceNames = array_map($target->instance(...), TopologyProfile::ROLES);
        try {
            $instances = $this->host->instances($instanceNames);
            foreach ($instances as $name => $instance) {
                $this->assertOperationResource($instance->metadata, $operation, $name);
            }
            $network = $this->host->network($target->network());
            if ($network !== null) {
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
            }
            if ($network !== null) {
                $this->networks->delete($network->name);
            }
            if ($this->host->instances($instanceNames) !== []) {
                throw new RuntimeException('A cold-build VM persisted after deletion.');
            }
            if ($this->host->network($target->network()) !== null) {
                throw new RuntimeException('A cold-build network persisted after deletion.');
            }
            $corrupt = $this->state->read('standby/corrupt.json');
            if (is_array($corrupt) && ($corrupt['operation_id'] ?? null) === $operation->value) {
                $this->state->delete('standby/corrupt.json');
            }

            return true;
        } catch (Throwable $exception) {
            $this->state->write('standby/corrupt.json', [
                'schema' => 2,
                'operation_id' => $operation->value,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /** @param array<string, string> $metadata */
    private function assertOperationResource(array $metadata, OperationId $operation, string $resource): void
    {
        if (($metadata['user.orbit.e2e.owner'] ?? null) !== 'orbit-e2e') {
            throw new RuntimeException("Standby resource {$resource} ownership identity does not match.");
        }
        if (($metadata['user.orbit.e2e.operation'] ?? null) !== $operation->value) {
            throw new RuntimeException("Standby resource {$resource} belongs to another operation.");
        }
    }
}
