<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\AtomicJsonStore;
use App\E2E\Value\ColdTopologyPlan;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\OperationId;
use App\E2E\Value\PreparedFingerprint;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologySnapshotIdentity;
use App\E2E\Value\TopologyTarget;
use App\Exceptions\E2E\ColdTopologyCleanupException;
use RuntimeException;
use Throwable;

/**
 * Build the topology snapshot from the generic base image when no promoted
 * generation exists. The topology snapshot names are fixed, so cleanup after a failed
 * build needs no intent record: every topology snapshot resource stamped with this
 * operation is deleted.
 *
 * @mago-expect lint:excessive-parameter-list,cyclomatic-complexity,kan-defect Cold construction keeps its exact resource transaction at one boundary.
 */
final readonly class TopologySnapshotBuilder
{
    public function __construct(
        private ColdTopologyConstructor $constructor,
        private TopologySnapshotManifestStore $manifests,
        private AtomicJsonStore $state,
        private string $mainWorktree,
        private TopologySnapshotIdentity $identity,
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
            throw new RuntimeException('Cold topology snapshot construction requires explicit permission.');
        }
        if ($this->state->read('topology-snapshot/corrupt.json') !== null) {
            throw new RuntimeException(
                'Cold topology snapshot construction is blocked until explicit recovery clears corrupt state.',
            );
        }
        if ($this->manifests->promoted() !== null) {
            throw new RuntimeException(
                'Cold topology snapshot construction is refused while a promoted generation exists.',
            );
        }

        $alias = $fingerprint->manifest['base_image_alias'] ?? null;
        if (! is_string($alias) || $alias === '') {
            throw new RuntimeException('The prepared fingerprint has no base image alias.');
        }

        $recipe = TopologyRecipe::registered($alias);
        $target = TopologyTarget::topologySnapshot($this->identity, $recipe);

        try {
            return $this->constructor->construct(new ColdTopologyPlan(
                $target,
                $this->mainWorktree,
                $mainSha,
                [$alias => $baseImageFingerprint],
                $laravel,
                $operation,
                ['user.orbit.e2e.operation' => $operation->value],
                $this->identity->slot,
            ));
        } catch (ColdTopologyCleanupException $exception) {
            $this->state->write('topology-snapshot/corrupt.json', [
                'schema' => 2,
                'operation_id' => $operation->value,
                'message' => $exception->getMessage(),
            ]);

            throw new RuntimeException(
                'Cold topology snapshot cleanup failed; explicit recovery is required.',
                previous: $exception,
            );
        }
    }

    /**
     * Delete every topology snapshot resource this operation created and prove absence.
     * A resource of another operation blocks the cleanup and marks the topology snapshot corrupt.
     */
    public function cleanupCold(OperationId $operation): bool
    {
        try {
            $cleanup = $this->constructor->cleanup(TopologyTarget::topologySnapshot($this->identity), $operation);
            if (! $cleanup->successful()) {
                throw new RuntimeException(implode('; ', $cleanup->refused));
            }
            $corrupt = $this->state->read('topology-snapshot/corrupt.json');
            if (is_array($corrupt) && ($corrupt['operation_id'] ?? null) === $operation->value) {
                $this->state->delete('topology-snapshot/corrupt.json');
            }

            return true;
        } catch (Throwable $exception) {
            $this->state->write('topology-snapshot/corrupt.json', [
                'schema' => 2,
                'operation_id' => $operation->value,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
