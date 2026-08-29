<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\AtomicJsonStore;
use App\E2E\State\OperationLock;
use App\E2E\State\StatePaths;
use App\E2E\Value\AttemptId;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\IncusInstance;
use App\E2E\Value\IncusNetwork;
use App\E2E\Value\OperationId;
use App\E2E\Value\ReleaseResult;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyTarget;
use RuntimeException;

/** @mago-expect lint:cyclomatic-complexity,excessive-parameter-list,kan-defect,too-many-methods Exact ordered cleanup keeps every ownership guard visible. */
final readonly class TopologyReleaser
{
    private const string ATTEMPT_PATTERN = '/\A[0-9a-f]{32}\z/D';

    private const int PENDING_SCHEMA = 2;

    public function __construct(
        private IncusHost $host,
        private IncusNetworkLifecycle $networks,
        private TopologyManifestStore $manifests,
        private AtomicJsonStore $state,
        private StatePaths $paths,
        private OperationId $operation,
        private ?HostCapacity $capacity = null,
        private ?AcquisitionRollback $acquisitionRollback = null,
    ) {}

    public function release(string $issue): ReleaseResult
    {
        TopologyTarget::assertIssue($issue);
        $lock = new OperationLock($this->paths);
        $operation = $this->operation;
        if (! $lock->acquire('topology-'.$issue, $operation)) {
            throw new RuntimeException('The issue topology is locked.');
        }
        try {
            return $this->releaseLocked($issue);
        } finally {
            $lock->release();
        }
    }

    /** @mago-expect lint:halstead Exact release evidence requires explicit ordered mutations. */
    private function releaseLocked(string $issue): ReleaseResult
    {
        $retained = $this->state->read('releases/'.$issue.'.json');
        if ($retained !== null) {
            $previous = ReleaseResult::fromArray($retained);

            $this->assertActiveArtifactsAbsent($issue);
            $pending = $this->state->read('release-pending/'.$issue.'.json');
            $retainedTarget = null;
            if ($pending !== null) {
                $pendingResult = $this->pendingRelease($pending, $issue);
                if ($pendingResult->toArray() !== $previous->toArray()) {
                    throw new RuntimeException('The pending and retained release evidence do not match.');
                }
                $retainedTarget = $this->pendingTarget($pending, $issue);
                $this->state->delete('release-pending/'.$issue.'.json');
            }

            return $this->replay($retainedTarget, $previous);
        }
        $pending = $this->state->read('release-pending/'.$issue.'.json');
        if ($pending !== null) {
            // The pending record names its own attempt, so the exact resources this
            // release must still prove are gone stay known without any live state.
            $pendingTarget = $this->pendingTarget($pending, $issue);

            return $this->replay($pendingTarget, $this->finalizePending($issue, $pending));
        }
        $lease = $this->state->read('leases/'.$issue.'.json');
        $topology = $this->manifests->active($issue);
        if ($topology === null && $lease === null) {
            throw new RuntimeException('The exact feature topology manifest does not exist.');
        }
        if (
            $lease === null
            || ($lease['issue'] ?? null) !== $issue
            || ! is_string($lease['operation_id'] ?? null)
        ) {
            throw new RuntimeException('The exact topology lease is invalid before release.');
        }
        $leaseAttempt = $this->leaseAttempt($lease);
        if (($lease['state'] ?? null) === 'acquiring') {
            return $this->releaseAbandonedAcquisition(TopologyTarget::feature($issue, $leaseAttempt), $lease);
        }
        if ($topology === null) {
            throw new RuntimeException('The exact feature topology manifest does not exist.');
        }
        if ($topology->attempt->value !== $leaseAttempt->value) {
            throw new RuntimeException('The topology lease and the active topology attempt do not match.');
        }
        $target = $topology->target;
        if (! in_array($lease['state'] ?? null, ['ready', 'syncing', 'failed'], true)) {
            throw new RuntimeException('The exact topology lease is invalid before release.');
        }
        $acquisitionOperation = new OperationId($lease['operation_id']);

        $released = [];
        $absent = [];
        $instanceNames = [];
        foreach (TopologyProfile::ROLES as $role) {
            $instanceNames[$role] = $target->instance($role);
        }
        $observedInstances = $this->host->instances(array_values($instanceNames));
        $instances = [];
        foreach (TopologyProfile::ROLES as $role) {
            $name = $instanceNames[$role];
            if (($topology->instances[$role] ?? null) !== $name) {
                throw new RuntimeException('A manifest resource identity changed before release.');
            }
            $instance = $observedInstances[$name] ?? null;
            if ($instance === null) {
                $absent[] = $name;
                continue;
            }
            $this->assertOwnership(
                $instance->metadata,
                $issue,
                $topology->attempt,
                $name,
                $topology->generation->id,
                $lease['operation_id'],
            );
            if ($instance->network !== $topology->network || $instance->mac !== $target->mac($role)) {
                throw new RuntimeException("Incus instance {$name} identity does not match the topology manifest.");
            }
            $instances[$role] = $instance;
        }
        if ($topology->network !== $target->network()) {
            throw new RuntimeException('The manifest network identity changed before release.');
        }
        $network = $this->host->network($target->network());
        if ($network === null) {
            $absent[] = $topology->network;
        }
        if ($network !== null) {
            $this->assertOwnership(
                $network->metadata,
                $issue,
                $topology->attempt,
                $target->network(),
                null,
                $lease['operation_id'],
            );
        }
        $running = [];
        foreach (TopologyProfile::ROLES as $role) {
            $instance = $instances[$role] ?? null;
            if ($instance !== null && $instance->isRunning()) {
                $running[] = $instance->name;
            }
        }
        if ($running !== []) {
            $this->host->forceStopAll($running);
            foreach ($running as $name) {
                $released[] = 'stopped:'.$name;
            }
        }
        $deletions = [];
        foreach (array_reverse(TopologyProfile::ROLES) as $role) {
            if (isset($instances[$role])) {
                $deletions[] = $instances[$role]->name;
            }
        }
        if ($deletions !== []) {
            $this->host->deleteInstances($deletions);
            foreach ($deletions as $name) {
                $released[] = 'deleted:'.$name;
            }
        }

        if ($this->host->instances(array_values($instanceNames)) !== []) {
            throw new RuntimeException('Cannot delete the topology network while an exact VM remains.');
        }

        if ($network !== null) {
            $this->networks->delete($target->network());
            $released[] = 'deleted:'.$target->network();
        }

        if ($this->host->network($target->network()) !== null) {
            throw new RuntimeException('Exact topology resources remain after release deletion.');
        }

        $result = new ReleaseResult($this->operation->value, bin2hex(random_bytes(16)), $released, $absent);
        $leaseState = $this->state->read('leases/'.$issue.'.json');
        $topologyState = $this->topologyState($topology);
        if ($topologyState === null) {
            throw new RuntimeException('The exact feature topology manifest disappeared before release finalization.');
        }
        $pending = [
            'schema' => self::PENDING_SCHEMA,
            'issue' => $issue,
            'attempt' => $topology->attempt->value,
            'acquisition_operation_id' => $acquisitionOperation->value,
            'operation_id' => $result->operationId,
            'evidence_id' => $result->evidenceId,
            'lease_sha256' => $leaseState === null ? null : $this->stateDigest($leaseState),
            'topology_sha256' => $this->stateDigest($topologyState),
            'result' => $result->toArray(),
        ];
        $this->state->write('release-pending/'.$issue.'.json', $pending);
        $this->finalizePending($issue, $pending, resourcesVerifiedAbsent: true);

        return $result;
    }

    /** @param array<array-key, mixed> $lease */
    private function releaseAbandonedAcquisition(TopologyTarget $target, array $lease): ReleaseResult
    {
        $required = [
            'schema',
            'issue',
            'attempt',
            'state',
            'operation_id',
            'expires_at',
            'pid',
            'process_start_identity',
            'acquired_at',
        ];
        if (
            array_diff(array_keys($lease), $required) !== []
            || array_diff($required, array_keys($lease)) !== []
            || $lease['schema'] !== 2
            || ! is_int($lease['pid'])
            || ! is_string($lease['process_start_identity'])
            || ! is_string($lease['acquired_at'])
            || ! OperationLock::isStale([
                'pid' => $lease['pid'],
                'process_start_identity' => $lease['process_start_identity'],
                'operation_id' => $lease['operation_id'],
                'acquired_at' => $lease['acquired_at'],
            ])
        ) {
            throw new RuntimeException('The abandoned acquisition owner identity is invalid or still live.');
        }
        $resources = [$target->network(), ...array_map($target->instance(...), TopologyProfile::ROLES)];
        $observedResources = $this->host->instances(array_slice($resources, 1));
        $network = $this->host->network($target->network());
        $observedResources[$target->network()] = $network;
        foreach (array_slice($resources, 1) as $resource) {
            $observedResources[$resource] ??= null;
        }
        $identity = [];
        foreach ($resources as $resource) {
            $current = $observedResources[$resource] ?? null;
            $identity[$resource] = $current === null
                ? null
                : [
                    'remote' => $current->remote,
                    'project' => $current->project,
                    'name' => $current->name,
                    'pool' => $current instanceof IncusInstance ? $current->pool : null,
                    'network' => $current instanceof IncusInstance ? $current->network : null,
                    'mac' => $current instanceof IncusInstance ? $current->mac : null,
                    'metadata' => $current->metadata,
                ];
        }
        $operationId = $lease['operation_id'] ?? null;
        if (! is_string($operationId)) {
            throw new RuntimeException('Lease operation identity is invalid.');
        }
        /** @return array<string, IncusInstance|IncusNetwork|null> */
        $inventory = function (array $names) use ($target): array {
            if (
                ! array_is_list($names)
                || array_filter($names, static fn (mixed $name): bool => ! is_string($name)) !== []
            ) {
                throw new RuntimeException('Rollback resource list is invalid.');
            }

            /** @var list<string> $names */
            return $this->rollbackInventory($target, $names);
        };
        /** @mago-expect analysis:less-specific-argument The validated adapter narrows resources at runtime. */
        $rollback = $this->acquisitionRollback ?? new AcquisitionRollback(
            $inventory,
            function (array $names): void {
                $this->host->stopAll($names);
            },
            function (array $names): void {
                $this->host->deleteInstances($names);
            },
            function (string $name): void {
                $this->networks->delete($name);
            },
        );
        $results = $rollback->cleanup($target, $resources, $identity, new OperationId($operationId));
        if (
            array_diff(
                $resources,
                array_keys(array_filter($results, static fn (string $result): bool => in_array(
                    $result,
                    ['removed', 'absent'],
                    true,
                ))),
            ) !== []
        ) {
            throw new RuntimeException('Abandoned acquisition cleanup is incomplete.');
        }
        $remainingInstances = $this->host->instances(array_slice($resources, 1));
        if ($remainingInstances !== [] || $this->host->network($target->network()) !== null) {
            throw new RuntimeException('Abandoned acquisition resources remain after cleanup.');
        }
        $this->forgetTopology($target->issue);
        $this->capacity?->release($target->issue, $target->requireAttempt(), new OperationId($operationId));
        $this->state->delete('leases/'.$target->issue.'.json');
        $result = new ReleaseResult(
            $this->operation->value,
            bin2hex(random_bytes(16)),
            array_values(array_map(
                static fn (string|int $resource): string => 'removed:'.$resource,
                array_keys(array_filter($results, static fn (string $status): bool => $status === 'removed')),
            )),
            array_values(array_map(
                static fn (string|int $resource): string => 'absent:'.$resource,
                array_keys(array_filter($results, static fn (string $status): bool => $status === 'absent')),
            )),
        );
        $this->state->write('releases/'.$target->issue.'.json', $result->toArray());

        return $result;
    }

    /** @param list<string> $resources @return array<string, IncusInstance|IncusNetwork|null> */
    private function rollbackInventory(TopologyTarget $target, array $resources): array
    {
        $instances = array_values(array_filter(
            $resources,
            static fn (string $resource): bool => $resource !== $target->network(),
        ));
        $inventory = $instances === [] ? [] : $this->host->instances($instances);
        $inventory[$target->network()] = $this->host->network($target->network());
        foreach ($instances as $instance) {
            $inventory[$instance] ??= null;
        }

        /** @var array<string, IncusInstance|IncusNetwork|null> $inventory */
        return $inventory;
    }

    /** @param array<array-key, mixed> $pending */
    private function finalizePending(
        string $issue,
        array $pending,
        bool $resourcesVerifiedAbsent = false,
    ): ReleaseResult {
        $result = $this->pendingRelease($pending, $issue);
        $target = $this->pendingTarget($pending, $issue);
        $lease = $this->state->read('leases/'.$issue.'.json');
        $topology = $this->manifests->active($issue);
        $topologyState = $this->topologyState($topology);
        $this->assertPendingState($lease, $pending['lease_sha256']);
        $this->assertPendingState($topologyState, $pending['topology_sha256']);

        if (! $resourcesVerifiedAbsent) {
            $instances = array_map($target->instance(...), TopologyProfile::ROLES);
            if ($this->host->instances($instances) !== [] || $this->host->network($target->network()) !== null) {
                throw new RuntimeException('Cannot finalize pending release while an exact topology resource exists.');
            }
        }

        // The pending record carries the acquisition operation, so the ledger slots
        // are returned even when the lease was already deleted by an earlier attempt.
        $this->capacity?->release(
            $issue,
            $target->requireAttempt(),
            $this->pendingAcquisitionOperation($pending),
        );

        if ($lease !== null) {
            $this->state->delete('leases/'.$issue.'.json');
        }
        if ($topology !== null) {
            $this->manifests->forgetActive($topology);
        }
        $this->state->write('releases/'.$issue.'.json', $result->toArray());
        $this->state->delete('release-pending/'.$issue.'.json');

        return $result;
    }

    /** @param array<array-key, mixed> $pending */
    private function pendingRelease(array $pending, string $issue): ReleaseResult
    {
        if (
            array_keys($pending) !== [
                'schema',
                'issue',
                'attempt',
                'acquisition_operation_id',
                'operation_id',
                'evidence_id',
                'lease_sha256',
                'topology_sha256',
                'result',
            ]
            || $pending['schema'] !== self::PENDING_SCHEMA
            || $pending['issue'] !== $issue
            || ! is_string($pending['attempt'])
            || preg_match(self::ATTEMPT_PATTERN, $pending['attempt']) !== 1
            || ! is_string($pending['acquisition_operation_id'])
            || ! is_string($pending['operation_id'])
            || ! is_string($pending['evidence_id'])
            || $pending['lease_sha256'] !== null
            && (! is_string($pending['lease_sha256'])
            || preg_match('/\A[a-f0-9]{64}\z/D', $pending['lease_sha256']) !== 1)
            || ! is_string($pending['topology_sha256'])
            || preg_match('/\A[a-f0-9]{64}\z/D', $pending['topology_sha256']) !== 1
            || ! is_array($pending['result'])
        ) {
            throw new RuntimeException('The pending release evidence is invalid.');
        }

        $result = ReleaseResult::fromArray($pending['result']);
        if ($pending['operation_id'] !== $result->operationId || $pending['evidence_id'] !== $result->evidenceId) {
            throw new RuntimeException('The pending release evidence identity does not match.');
        }

        return $result;
    }

    /**
     * The exact attempt a pending release finishes. The record names it, so the
     * resources this release must prove are gone stay known without any live state.
     *
     * @param array<array-key, mixed> $pending
     */
    private function pendingTarget(array $pending, string $issue): TopologyTarget
    {
        return TopologyTarget::feature($issue, self::requireAttempt(
            $pending['attempt'] ?? null,
            'The pending release evidence is invalid.',
        ));
    }

    /** @param array<array-key, mixed> $pending */
    private function pendingAcquisitionOperation(array $pending): OperationId
    {
        return new OperationId(self::requireString(
            $pending['acquisition_operation_id'] ?? null,
            'The pending release evidence is invalid.',
        ));
    }

    /** @param array<array-key, mixed>|null $state */
    private function assertPendingState(?array $state, mixed $expectedDigest): void
    {
        if ($state === null) {
            return;
        }
        if (! is_string($expectedDigest) || $this->stateDigest($state) !== $expectedDigest) {
            throw new RuntimeException('The pending release state does not match active topology state.');
        }
    }

    /** @param array<array-key, mixed> $state */
    private function stateDigest(array $state): string
    {
        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * A null target means retained evidence with no pending record left to name an
     * attempt, so no resource identity exists to re-check; `assertActiveArtifactsAbsent()`
     * has already refused every case where active topology state still exists.
     */
    private function replay(?TopologyTarget $target, ReleaseResult $previous): ReleaseResult
    {
        if ($target !== null) {
            $names = array_map($target->instance(...), TopologyProfile::ROLES);
            if ($this->host->instances($names) !== [] || $this->host->network($target->network()) !== null) {
                throw new RuntimeException('Cannot replay retained evidence while an exact topology resource exists.');
            }
        }

        return new ReleaseResult(
            $this->operation->value,
            $previous->evidenceId,
            [],
            [...$previous->released, ...$previous->alreadyAbsent],
        );
    }

    private function assertActiveArtifactsAbsent(string $issue): void
    {
        $paths = [
            $this->paths->root().'/leases/'.$issue.'.json',
            $this->paths->root().'/topologies/'.$issue.'.json',
            $this->paths->root().'/topologies/'.$issue.'/active.json',
        ];

        foreach ($paths as $path) {
            if (file_exists($path) || is_link($path)) {
                throw new RuntimeException('Cannot replay retained evidence while active topology state exists.');
            }
        }
    }

    /**
     * A lease without an exact attempt names no resources, so release refuses it
     * instead of falling back to whichever attempt the active pointer happens to name.
     *
     * @param array<array-key, mixed> $lease
     */
    private function leaseAttempt(array $lease): AttemptId
    {
        return self::requireAttempt(
            $lease['attempt'] ?? null,
            'The exact topology lease is invalid before release.',
        );
    }

    private static function requireAttempt(mixed $value, string $message): AttemptId
    {
        $attempt = self::requireString($value, $message);

        if (preg_match(self::ATTEMPT_PATTERN, $attempt) !== 1) {
            throw new RuntimeException($message);
        }

        return new AttemptId($attempt);
    }

    private static function requireString(mixed $value, string $message): string
    {
        if (! is_string($value)) {
            throw new RuntimeException($message);
        }

        return $value;
    }

    /** @return array<array-key, mixed>|null */
    private function topologyState(?FeatureTopology $topology): ?array
    {
        return $topology?->toArray();
    }

    private function forgetTopology(string $issue): void
    {
        $topology = $this->manifests->active($issue);

        if ($topology !== null) {
            $this->manifests->forgetActive($topology);
        }
    }

    /** @param array<string, string> $metadata */
    private function assertOwnership(
        array $metadata,
        string $issue,
        AttemptId $attempt,
        string $resource,
        ?string $generation,
        string $operation,
    ): void {
        if (
            ($metadata['user.orbit.e2e.owner'] ?? null) !== 'orbit-e2e'
            || ($metadata['user.orbit.e2e.issue'] ?? null) !== $issue
            || ($metadata['user.orbit.e2e.attempt'] ?? null) !== $attempt->value
            || $generation !== null
            && ($metadata['user.orbit.e2e.generation'] ?? null) !== $generation
            || ($metadata['user.orbit.e2e.operation'] ?? null) !== $operation
        ) {
            throw new RuntimeException("Incus resource {$resource} ownership does not match the exact issue.");
        }
    }
}
