<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\AtomicJsonStore;
use App\E2E\State\OperationLock;
use App\E2E\State\StatePaths;
use App\E2E\Value\AttemptId;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\IncusInstance;
use App\E2E\Value\IncusNetwork;
use App\E2E\Value\OperationId;
use App\E2E\Value\ReleaseResult;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyTarget;
use RuntimeException;

/**
 * Release one exact topology attempt and prove its resources are gone.
 *
 * Every Incus resource is revalidated against the attempt record (type, exact
 * name, owner, issue, attempt, generation, network attachment, MAC, and the
 * source mount device) before deletion; any mismatch blocks the release. A
 * receipt is kept per attempt, so a repeated release verifies absence again and
 * touches nothing else.
 *
 * @mago-expect lint:cyclomatic-complexity,excessive-parameter-list,kan-defect,too-many-methods Exact ordered cleanup keeps every ownership guard visible.
 */
final readonly class TopologyReleaser
{
    private const int PENDING_SCHEMA = 3;

    public function __construct(
        private IncusHost $host,
        private IncusNetworkLifecycle $networks,
        private TopologyManifestStore $manifests,
        private AtomicJsonStore $state,
        private StatePaths $paths,
        private OperationId $operation,
        private ReleaseReceiptStore $receipts,
        private ?HostCapacity $capacity = null,
        private ?AcquisitionRollback $acquisitionRollback = null,
    ) {}

    public function release(string $issue, AttemptId $attempt): ReleaseResult
    {
        TopologyTarget::assertIssue($issue);
        $lock = new OperationLock($this->paths);
        if (! $lock->acquire('topology-'.$issue, $this->operation)) {
            throw new RuntimeException('The issue topology is locked.');
        }
        try {
            return $this->releaseLocked(TopologyTarget::feature($issue, $attempt));
        } finally {
            $lock->release();
        }
    }

    /** @mago-expect lint:halstead Exact release evidence requires explicit ordered mutations. */
    private function releaseLocked(TopologyTarget $target): ReleaseResult
    {
        $issue = $target->issue;
        $attempt = $target->requireAttempt();
        $receipt = $this->receipts->read($issue, $attempt);
        if ($receipt !== null) {
            $this->assertAttemptArtifactsAbsent($target);
            $pending = $this->state->read($this->pendingPath($target));
            if ($pending !== null) {
                if ($this->pendingRelease($pending, $target)->toArray() !== $receipt->toArray()) {
                    throw new RuntimeException('The pending and retained release evidence do not match.');
                }
                $this->state->delete($this->pendingPath($target));
            }

            return $this->replay($target, $receipt);
        }
        $pending = $this->state->read($this->pendingPath($target));
        if ($pending !== null) {
            return $this->finalizePending($target, $pending);
        }

        $lease = $this->state->read('leases/'.$issue.'.json');
        if ($lease === null) {
            throw new RuntimeException('The exact topology attempt does not exist.');
        }
        if (
            ($lease['issue'] ?? null) !== $issue
            || ! is_string($lease['operation_id'] ?? null)
            || preg_match('/\A[0-9a-f]{32}\z/D', $lease['operation_id']) !== 1
        ) {
            throw new RuntimeException('The exact topology lease is invalid before release.');
        }
        if ($this->leaseAttempt($lease)->value !== $attempt->value) {
            throw new RuntimeException('The topology lease names another attempt.');
        }
        if (($lease['state'] ?? null) === 'acquiring') {
            return $this->releaseAbandonedAcquisition($target, $lease);
        }
        if (! in_array($lease['state'] ?? null, ['ready', 'syncing', 'failed'], true)) {
            throw new RuntimeException('The exact topology lease is invalid before release.');
        }
        $topology = $this->manifests->read($issue, $attempt) ?? throw new RuntimeException(
            'The exact topology attempt does not exist.',
        );
        $active = $this->manifests->active($issue);
        if ($active === null || $active->attempt->value !== $attempt->value) {
            throw new RuntimeException('The topology attempt is not the active topology attempt.');
        }
        $acquisitionOperation = new OperationId($lease['operation_id']);

        [$instances, $absent] = $this->revalidatedInstances($topology, $lease['operation_id']);
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
                $attempt,
                $target->network(),
                null,
                $lease['operation_id'],
            );
        }

        $released = [];
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
            if (array_key_exists($role, $instances)) {
                $deletions[$role] = $instances[$role]->name;
            }
        }
        if ($deletions !== []) {
            $this->host->deleteInstances(array_values($deletions));
            foreach ($deletions as $role => $name) {
                $released[] = 'deleted:'.$name;
                if (array_key_exists($role, $topology->mounts)) {
                    $released[] = 'device:'.$name.':'.$topology->mounts[$role]['device'];
                }
            }
        }

        $instanceNames = array_map($target->instance(...), TopologyProfile::ROLES);
        if ($this->host->instances($instanceNames) !== []) {
            throw new RuntimeException('Cannot delete the topology network while an exact VM remains.');
        }
        if ($network !== null) {
            $this->networks->delete($target->network());
            $released[] = 'deleted:'.$target->network();
        }
        if ($this->host->network($target->network()) !== null) {
            throw new RuntimeException('Exact topology resources remain after release deletion.');
        }

        $result = new ReleaseResult(
            $this->operation->value,
            bin2hex(random_bytes(16)),
            $issue,
            $attempt,
            $topology->purpose,
            $released,
            $absent,
            [...$instanceNames, $target->network()],
            ReleaseResult::now(),
        );
        $leaseState = $this->state->read('leases/'.$issue.'.json');
        $topologyState = $this->manifests->read($issue, $attempt)?->toArray();
        if ($topologyState === null) {
            throw new RuntimeException('The exact feature topology manifest disappeared before release finalization.');
        }
        $pending = [
            'schema' => self::PENDING_SCHEMA,
            'issue' => $issue,
            'attempt' => $attempt->value,
            'acquisition_operation_id' => $acquisitionOperation->value,
            'operation_id' => $result->operationId,
            'evidence_id' => $result->evidenceId,
            'lease_sha256' => $leaseState === null ? null : $this->stateDigest($leaseState),
            'topology_sha256' => $this->stateDigest($topologyState),
            'result' => $result->toArray(),
        ];
        $this->state->write($this->pendingPath($target), $pending);
        $this->finalizePending($target, $pending, resourcesVerifiedAbsent: true);

        return $result;
    }

    /**
     * Every live instance must still be the exact VM the attempt record names.
     *
     * @return array{array<string, IncusInstance>, list<string>}
     */
    private function revalidatedInstances(FeatureTopology $topology, string $operation): array
    {
        $target = $topology->target;
        $instanceNames = [];
        foreach (TopologyProfile::ROLES as $role) {
            $instanceNames[$role] = $target->instance($role);
        }
        $observed = $this->host->instances(array_values($instanceNames));
        $instances = [];
        $absent = [];
        foreach (TopologyProfile::ROLES as $role) {
            $name = $instanceNames[$role];
            if (($topology->instances[$role] ?? null) !== $name) {
                throw new RuntimeException('A manifest resource identity changed before release.');
            }
            $instance = $observed[$name] ?? null;
            if ($instance === null) {
                $absent[] = $name;
                continue;
            }
            $this->assertOwnership(
                $instance->metadata,
                $target->issue,
                $topology->attempt,
                $name,
                $topology->generation->id,
                $operation,
            );
            if ($instance->network !== $topology->network || $instance->mac !== $target->mac($role)) {
                throw new RuntimeException("Incus instance {$name} identity does not match the topology manifest.");
            }
            $this->assertMount($instance, $topology->mounts[$role] ?? null);
            $instances[$role] = $instance;
        }

        return [$instances, $absent];
    }

    /**
     * The source mount device must match the record exactly; a foreign or altered
     * mount is never deleted from under a VM this release does not fully own.
     *
     * @param array{device:string,source:string,path:string}|null $mount
     */
    private function assertMount(IncusInstance $instance, ?array $mount): void
    {
        $recorded = $instance->disk(FeatureTopology::SOURCE_DEVICE);
        if ($mount === null) {
            if ($recorded !== null) {
                throw new RuntimeException(
                    "Incus instance {$instance->name} carries a source mount the topology manifest does not record.",
                );
            }

            return;
        }
        $observed = $instance->disk($mount['device']);
        if ($observed === null || $observed['source'] !== $mount['source'] || $observed['path'] !== $mount['path']) {
            throw new RuntimeException(
                "Incus instance {$instance->name} source mount does not match the topology manifest.",
            );
        }
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
        $instanceRoles = array_combine(
            array_map($target->instance(...), TopologyProfile::ROLES),
            TopologyProfile::ROLES,
        );
        $resources = [$target->network(), ...array_keys($instanceRoles)];
        $observedResources = $this->rollbackInventory($target, array_keys($instanceRoles));
        // An interrupted acquisition may already have written its record; when it
        // did, every live VM must carry exactly the source mount the record names.
        $topology = $this->manifests->read($target->issue, $target->requireAttempt());
        $identity = [];
        foreach ($resources as $resource) {
            $current = $observedResources[$resource] ?? null;
            if (! $current instanceof IncusInstance && ! $current instanceof IncusNetwork) {
                $identity[$resource] = null;
                continue;
            }
            if ($topology !== null && $current instanceof IncusInstance) {
                $this->assertMount($current, $topology->mounts[$instanceRoles[$resource]] ?? null);
            }
            $identity[$resource] = [
                'remote' => $current->remote,
                'project' => $current->project,
                'name' => $current->name,
                'pool' => $current instanceof IncusInstance ? $current->pool : null,
                'network' => $current instanceof IncusInstance ? $current->network : null,
                'mac' => $current instanceof IncusInstance ? $current->mac : null,
                'metadata' => $current->metadata,
            ];
        }
        $operationId = $lease['operation_id'];
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
        if ($topology !== null) {
            $this->manifests->forgetActive($topology);
        }
        $this->capacity?->release($target->issue, $target->requireAttempt(), new OperationId($operationId));
        $this->state->delete('leases/'.$target->issue.'.json');
        $result = new ReleaseResult(
            $this->operation->value,
            bin2hex(random_bytes(16)),
            $target->issue,
            $target->requireAttempt(),
            $topology === null ? AttemptPurpose::Discovery : $topology->purpose,
            array_values(array_map(
                static fn (string|int $resource): string => 'removed:'.$resource,
                array_keys(array_filter($results, static fn (string $status): bool => $status === 'removed')),
            )),
            array_values(array_map(
                static fn (string|int $resource): string => 'absent:'.$resource,
                array_keys(array_filter($results, static fn (string $status): bool => $status === 'absent')),
            )),
            [...array_slice($resources, 1), $target->network()],
            ReleaseResult::now(),
        );
        $this->receipts->write($result);

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
        TopologyTarget $target,
        array $pending,
        bool $resourcesVerifiedAbsent = false,
    ): ReleaseResult {
        $issue = $target->issue;
        $attempt = $target->requireAttempt();
        $result = $this->pendingRelease($pending, $target);
        $lease = $this->state->read('leases/'.$issue.'.json');
        $topology = $this->manifests->read($issue, $attempt);
        $this->assertPendingState($lease, $pending['lease_sha256']);
        $this->assertPendingState($topology?->toArray(), $pending['topology_sha256']);

        if (! $resourcesVerifiedAbsent) {
            $this->assertResourcesAbsent(
                $target,
                'Cannot finalize pending release while an exact topology resource exists.',
            );
        }

        // The pending record carries the acquisition operation, so the ledger slots
        // are returned even when the lease was already deleted by an earlier attempt.
        $this->capacity?->release($issue, $attempt, $this->pendingAcquisitionOperation($pending));

        // Only a lease that still names this attempt is removed; a newer attempt's
        // lease is never touched by finishing an older release.
        if ($lease !== null && ($lease['attempt'] ?? null) === $attempt->value) {
            $this->state->delete('leases/'.$issue.'.json');
        }
        if ($topology !== null) {
            $this->manifests->forgetActive($topology);
        }
        $this->receipts->write($result);
        $this->state->delete($this->pendingPath($target));

        return $result;
    }

    /** @param array<array-key, mixed> $pending */
    private function pendingRelease(array $pending, TopologyTarget $target): ReleaseResult
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
            || $pending['issue'] !== $target->issue
            || $pending['attempt'] !== $target->requireAttempt()->value
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
        if (
            $pending['operation_id'] !== $result->operationId
            || $pending['evidence_id'] !== $result->evidenceId
            || $result->issue !== $target->issue
            || $result->attempt->value !== $target->requireAttempt()->value
        ) {
            throw new RuntimeException('The pending release evidence identity does not match.');
        }

        return $result;
    }

    /** @param array<array-key, mixed> $pending */
    private function pendingAcquisitionOperation(array $pending): OperationId
    {
        $operation = $pending['acquisition_operation_id'] ?? null;
        if (! is_string($operation)) {
            throw new RuntimeException('The pending release evidence is invalid.');
        }

        return new OperationId($operation);
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
     * A repeated release proves the exact resources are still absent and reports
     * the recorded evidence as already absent; the receipt itself is untouched.
     */
    private function replay(TopologyTarget $target, ReleaseResult $previous): ReleaseResult
    {
        $this->assertResourcesAbsent(
            $target,
            'Cannot replay retained evidence while an exact topology resource exists.',
        );

        return new ReleaseResult(
            $this->operation->value,
            $previous->evidenceId,
            $previous->issue,
            $previous->attempt,
            $previous->purpose,
            [],
            [...$previous->released, ...$previous->alreadyAbsent],
            [...array_map($target->instance(...), TopologyProfile::ROLES), $target->network()],
            ReleaseResult::now(),
        );
    }

    private function assertResourcesAbsent(TopologyTarget $target, string $message): void
    {
        $names = array_map($target->instance(...), TopologyProfile::ROLES);
        if ($this->host->instances($names) !== [] || $this->host->network($target->network()) !== null) {
            throw new RuntimeException($message);
        }
    }

    /** Retained evidence is only replayed while no state of this attempt remains active. */
    private function assertAttemptArtifactsAbsent(TopologyTarget $target): void
    {
        $issue = $target->issue;
        $attempt = $target->requireAttempt()->value;
        $lease = $this->state->read('leases/'.$issue.'.json');
        $record = $this->paths->root().'/topologies/'.$issue.'/'.$attempt.'.json';
        $pointer = $this->state->read('topologies/'.$issue.'/active.json');
        if (
            $lease !== null
            && ($lease['attempt'] ?? null) === $attempt
            || file_exists($record)
            || is_link($record)
            || $pointer !== null
            && ($pointer['attempt'] ?? null) === $attempt
        ) {
            throw new RuntimeException('Cannot replay retained evidence while active topology state exists.');
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
        $attempt = $lease['attempt'] ?? null;
        if (! is_string($attempt) || preg_match('/\A[0-9a-f]{32}\z/D', $attempt) !== 1) {
            throw new RuntimeException('The exact topology lease is invalid before release.');
        }

        return new AttemptId($attempt);
    }

    private function pendingPath(TopologyTarget $target): string
    {
        return 'release-pending/'.$target->issue.'/'.$target->requireAttempt()->value.'.json';
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
