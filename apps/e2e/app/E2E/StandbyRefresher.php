<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Git\GitRepository;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\OperationLock;
use App\E2E\Value\IncusInstance;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\OperationId;
use App\E2E\Value\PreparedFingerprint;
use App\E2E\Value\RefreshResult;
use App\E2E\Value\StandbyGeneration;
use App\E2E\Value\StandbyIdentity;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationMode;
use RuntimeException;
use Throwable;

/** @mago-expect lint:excessive-parameter-list,cyclomatic-complexity,kan-defect,too-many-methods Explicit workflow dependencies preserve the promotion boundary. */
final readonly class StandbyRefresher
{
    private const int GENERATION_MUTATION_LOCK_TIMEOUT_SECONDS = 3600;

    public function __construct(
        private IncusHost $host,
        private IncusNetworkLifecycle $networks,
        private PreparedStateFingerprint $fingerprints,
        private StandbyManifestStore $manifests,
        private StandbyBuilder $builder,
        private WorktreeSynchronizer $synchronizer,
        private TopologyConverger $converger,
        private TopologyVerifier $verifier,
        private LaravelReleaseResolver $laravel,
        private OperationLock $lock,
        private OperationLock $generationLock,
        private AtomicJsonStore $state,
        private GitRepository $git,
        private string $mainWorktree,
        private OperationId $operation,
        private StandbyIdentity $identity,
        private StandbyAvailability $availability,
        private int $refreshLockTimeoutSeconds = 3600,
    ) {}

    public function request(string $mainSha, bool $allowCold = false): RefreshResult
    {
        if (preg_match('/\A[a-f0-9]{40}\z/D', $mainSha) !== 1) {
            throw new RuntimeException('The refresh SHA is invalid.');
        }

        if (! $this->lock->acquire(
            'standby-refresh',
            $this->operation,
            timeoutSeconds: $this->refreshLockTimeoutSeconds,
        )) {
            return new RefreshResult(
                'failed',
                $this->operation->value,
                error: 'Unable to acquire the standby refresh lock.',
            );
        }

        try {
            try {
                return $this->refresh($mainSha, $allowCold, $this->operation);
            } catch (Throwable $exception) {
                return new RefreshResult('failed', $this->operation->value, error: $exception->getMessage());
            }
        } finally {
            $this->lock->release();
        }
    }

    public function restore(): StandbyGeneration
    {
        if (! $this->lock->acquire(
            'standby-refresh',
            $this->operation,
            timeoutSeconds: $this->refreshLockTimeoutSeconds,
        )) {
            throw new RuntimeException('Unable to acquire the standby refresh lock.');
        }

        try {
            $generation = $this->manifests->promoted();
            if ($generation === null) {
                throw new RuntimeException('There is no promoted standby generation.');
            }
            $this->availability->assertAvailable($generation);

            $this->withGenerationMutationLock($this->operation, function () use ($generation): void {
                $this->deleteUnpromotedSnapshots($generation);
                $this->stopAndProve();
                $this->restoreSnapshots($generation);
                $this->assertStopped();
            });
            $this->state->delete('standby/corrupt.json');

            return $generation;
        } finally {
            $this->lock->release();
        }
    }

    private function refresh(string $mainSha, bool $allowCold, OperationId $operation): RefreshResult
    {
        if ($this->git->commit('HEAD') !== $mainSha || $this->git->dirtyOverlay() !== null) {
            throw new RuntimeException('The host main checkout does not match the requested clean SHA.');
        }

        $promoted = null;
        /** @var ?PreparedFingerprint $promotedStructural */
        $promotedStructural = null;
        $mutated = false;
        $generationMutationLockHeld = false;
        $timings = [];
        try {
            $promoted = $this->manifests->promoted();
            if ($promoted === null && ! $allowCold) {
                throw new RuntimeException('Cold standby construction requires explicit permission.');
            }
            $structural = $this->fingerprints->forCommit($mainSha);
            $desired = $structural;
            if ($promoted !== null) {
                $desired = $this->fingerprints->withLaravel($structural, $promoted->laravel);
            }
            if ($promoted !== null) {
                $promotedStructural = new PreparedFingerprint($promoted->structuralFingerprint, [
                    'cold_epoch' => $promoted->coldEpoch,
                    'base_image_alias' => $promoted->baseImageAlias,
                ]);
                $desiredCold = [$desired->manifest['cold_epoch'], $desired->manifest['base_image_alias']];
                $promotedCold = [$promoted->coldEpoch, $promoted->baseImageAlias];
                if ($desiredCold !== $promotedCold) {
                    throw new RuntimeException('Cold base changed; recovery-required cold standby rebuild.');
                }
            }
            if ($promoted !== null && ! $promotedStructural instanceof PreparedFingerprint) {
                throw new RuntimeException('The promoted standby fingerprint is unavailable.');
            }
            if (
                ! is_string($desired->manifest['base_image_alias'] ?? null)
                || $desired->manifest['base_image_alias'] === ''
            ) {
                throw new RuntimeException('The prepared fingerprint has no base image alias.');
            }
            $alias = $desired->manifest['base_image_alias'];
            $baseImageFingerprint = $promoted?->baseImageFingerprint ?? $this->host->imageFingerprint($alias);
            if (
                $promoted !== null
                && $promotedStructural->value === $structural->value
                && $promoted->preparedFingerprint === $desired->value
            ) {
                if (! $this->generationLock->acquire('standby-generation', $operation, timeoutSeconds: 3600)) {
                    throw new RuntimeException('Unable to acquire the standby generation lock for standby probe.');
                }
                try {
                    $this->assertGenerationAvailable($promoted);
                    $this->assertStopped();
                } finally {
                    $this->generationLock->release();
                }

                return new RefreshResult('unchanged', $operation->value, $promoted->id);
            }

            $release =
                $promoted !== null && $promotedStructural->value === $structural->value
                    ? $promoted->laravel
                    : $this->laravel->resolve('>=13.0.0');
            $desired = $this->fingerprints->withLaravel($structural, $release);
            $target = TopologyTarget::standby($this->identity);

            if ($promoted === null) {
                $mutated = true;
                $source = $this->builder->build(
                    $mainSha,
                    $desired,
                    $baseImageFingerprint,
                    $release,
                    $allowCold,
                    $operation,
                );
            } else {
                if (! $this->generationLock->acquire(
                    'standby-generation',
                    $operation,
                    timeoutSeconds: self::GENERATION_MUTATION_LOCK_TIMEOUT_SECONDS,
                )) {
                    throw new RuntimeException('Unable to acquire the standby generation lock for standby mutation.');
                }
                $generationMutationLockHeld = true;
                // A manifest that names resources the host lost is stale, not corrupt:
                // refuse with the recovery command before anything mutates the standby.
                $this->availability->assertAvailable($promoted);
                $mutated = true;
                $this->assertStopped();
                $this->deleteUnpromotedSnapshots($promoted);
                $this->networks->reconcile($target->network());
                $this->measure($timings, 'restore', fn () => $this->restoreSnapshots($promoted));
                $this->measure($timings, 'start', fn () => $this->startAll());
                $source = $this->measure($timings, 'sync', fn () => $this->synchronizer->sync(
                    $target,
                    $this->mainWorktree,
                ));
                if ($source->dirty || $source->hostSha !== $mainSha || $source->guestSha !== $mainSha) {
                    throw new RuntimeException('Standby source is not clean merged main.');
                }
                $this->measure($timings, 'converge', fn () => $this->converger->converge($target, $source, $release));
            }

            $verification = $this->measure($timings, 'verify', fn () => $this->verifier->verify(
                $target,
                VerificationMode::Readiness,
                $source,
            ));
            if (! $verification->passed) {
                throw new RuntimeException('Standby verification failed.');
            }
            $proof = $this->measure(
                $timings,
                'proof',
                fn () => $this->verifier->verify($target, VerificationMode::Proof, $source),
            );
            if (! $proof->passed) {
                throw new RuntimeException('Standby proof verification failed.');
            }
            $this->measure($timings, 'stop', fn () => $this->stopAndProve());
            $generation = $this->measure($timings, 'snapshot', fn () => $this->snapshot(
                $mainSha,
                $desired->value,
                $baseImageFingerprint,
                $release,
                $promoted?->id,
                $structural->value,
                $desired->manifest,
            ));
            $this->generationLock->release();
            $generationMutationLockHeld = false;
            if (! $this->generationLock->acquire('standby-generation', $operation, timeoutSeconds: 3600)) {
                throw new RuntimeException('Unable to acquire the standby generation lock for promotion.');
            }
            try {
                $current = $this->manifests->promoted();
                if ($promoted?->toArray() !== $current?->toArray()) {
                    throw new RuntimeException('The promoted standby generation changed during refresh.');
                }
                $this->manifests->record($generation);
                $this->manifests->promote($generation);
            } finally {
                $this->generationLock->release();
            }
            $this->prune($generation);

            return new RefreshResult('promoted', $operation->value, $generation->id);
        } catch (Throwable $exception) {
            if ($generationMutationLockHeld) {
                $this->generationLock->release();
                $generationMutationLockHeld = false;
            }
            $recovered = ! $mutated || $this->rollback($promoted);
            $error = $exception->getMessage();
            if (! $recovered) {
                $error .= ' The previous snapshot could not be restored; the standby is marked corrupt.';
            }

            return new RefreshResult('failed', $operation->value, $promoted?->id, $error);
        }
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function measure(array &$timings, string $phase, callable $operation): mixed
    {
        $started = microtime(true);
        try {
            return $operation();
        } finally {
            $timings[$phase] = microtime(true) - $started;
        }
    }

    private function restoreSnapshots(StandbyGeneration $generation): void
    {
        $target = TopologyTarget::standby($this->identity);
        $snapshots = [];
        foreach (TopologyProfile::ROLES as $role) {
            $snapshots[$target->instance($role)] = $generation->snapshots[$role];
        }
        $this->host->restoreAll($snapshots);
    }

    private function assertGenerationAvailable(StandbyGeneration $generation): void
    {
        $this->availability->assertAvailable($generation);
    }

    private function startAll(): void
    {
        $target = TopologyTarget::standby($this->identity);
        $instances = array_map($target->instance(...), TopologyProfile::ROLES);
        $this->host->startAll($instances);
        $this->host->waitForRestoredHostStates($instances);
    }

    private function stopAll(): void
    {
        $target = TopologyTarget::standby($this->identity);
        $instances = array_map($target->instance(...), TopologyProfile::ROLES);
        $this->host->stopAll($instances);
    }

    private function stopAndProve(): void
    {
        $this->stopAll();
        $this->assertStopped();
    }

    private function assertStopped(): void
    {
        $target = TopologyTarget::standby($this->identity);
        $instances = array_map($target->instance(...), TopologyProfile::ROLES);
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $observed = $this->host->instances($instances);
            if (count($observed) !== count($instances)) {
                throw new RuntimeException('A standby VM is missing while checking its power state.');
            }
            if (array_all($observed, static fn (IncusInstance $instance): bool => $instance->isStopped())) {
                return;
            }
            usleep(100_000);
        }

        throw new RuntimeException('The standby VMs did not stop within the bounded wait.');
    }

    private function snapshot(
        string $mainSha,
        string $fingerprint,
        string $baseImageFingerprint,
        LaravelRelease $laravel,
        ?string $previousGenerationId,
        string $structuralFingerprint,
        array $manifest,
    ): StandbyGeneration {
        $id = substr($mainSha, 0, 12).'-'.substr($fingerprint, 0, 12);
        $snapshot = 'main-'.$id;
        $snapshots = [];
        $target = TopologyTarget::standby($this->identity);
        $stale = $this->deleteCandidateSnapshots($target, $snapshot);
        if ($stale !== []) {
            throw new RuntimeException(
                'Failed to remove stale candidate snapshots: '.$this->candidateCleanupFailure($stale).'.',
            );
        }
        try {
            $requests = [];
            foreach (TopologyProfile::ROLES as $role) {
                $requests[$target->instance($role)] = $snapshot;
                $snapshots[$role] = $snapshot;
            }
            $this->host->snapshotAll($requests);
            $this->host->assertOwnedSnapshots($requests);
        } catch (Throwable $exception) {
            $failedCleanup = $this->deleteCandidateSnapshots($target, $snapshot);
            if ($failedCleanup !== []) {
                throw new RuntimeException(
                    $exception->getMessage()
                    .' Candidate snapshot cleanup failed: '
                    .$this->candidateCleanupFailure($failedCleanup)
                    .'.',
                    previous: $exception,
                );
            }

            throw $exception;
        }

        if (
            ! is_int($manifest['schema'])
            || ! is_array($manifest['topology'] ?? null)
            || ! is_string($manifest['cold_epoch'])
            || ! is_string($manifest['base_image_alias'])
            || ! is_string($manifest['topology']['profile'])
            || ! is_array($manifest['topology']['roles'])
            || ! is_array($manifest['topology']['checkout_roles'])
            || ! array_all($manifest['topology']['roles'], static fn (mixed $value, string|int $key): bool => is_string(
                $value,
            ))
            || ! array_all($manifest['topology']['checkout_roles'], static fn (
                mixed $value,
                string|int $key,
            ): bool => is_string($value))
        ) {
            throw new RuntimeException('The prepared fingerprint manifest has an invalid topology shape.');
        }
        /** @var list<string> $topologyRoles */
        $topologyRoles = array_values($manifest['topology']['roles']);
        /** @var list<string> $checkoutRoles */
        $checkoutRoles = array_values($manifest['topology']['checkout_roles']);

        return new StandbyGeneration(
            $id,
            $mainSha,
            $snapshots,
            $fingerprint,
            $baseImageFingerprint,
            $laravel,
            $structuralFingerprint,
            $manifest['schema'],
            $manifest['cold_epoch'],
            $manifest['base_image_alias'],
            $manifest['topology']['profile'],
            $topologyRoles,
            $checkoutRoles,
            $previousGenerationId,
        );
    }

    /** @return array<string, string> */
    private function deleteCandidateSnapshots(TopologyTarget $target, string $snapshot): array
    {
        $snapshots = [];
        foreach (TopologyProfile::ROLES as $role) {
            $snapshots[$target->instance($role)] = $snapshot;
        }

        try {
            $this->host->deleteSnapshotsIfExist($snapshots);

            return [];
        } catch (Throwable $exception) {
            return ['batch' => $exception->getMessage()];
        }
    }

    /** @param array<string, string> $failures */
    private function candidateCleanupFailure(array $failures): string
    {
        return implode('; ', array_map(
            fn (string $role, string $message): string => "{$role} ({$message})",
            array_keys($failures),
            $failures,
        ));
    }

    /** Restore the promoted snapshot after a failed mutation; a failed restore marks the standby corrupt. */
    private function rollback(?StandbyGeneration $generation): bool
    {
        if ($generation === null) {
            return $this->builder->cleanupCold($this->operation);
        }

        try {
            $this->withGenerationMutationLock($this->operation, function () use ($generation): void {
                $this->deleteUnpromotedSnapshots($generation);
                $this->stopAndProve();
                $this->restoreSnapshots($generation);
                $this->assertStopped();
            });

            return true;
        } catch (Throwable $exception) {
            $this->markCorrupt($exception);

            return false;
        }
    }

    private function withGenerationMutationLock(OperationId $operation, callable $mutation): mixed
    {
        if (! $this->generationLock->acquire('standby-generation', $operation, timeoutSeconds: 3600)) {
            throw new RuntimeException('Unable to acquire the standby generation lock for standby mutation.');
        }

        try {
            return $mutation();
        } finally {
            $this->generationLock->release();
        }
    }

    private function deleteUnpromotedSnapshots(StandbyGeneration $promoted): void
    {
        foreach ($this->manifests->prunable($promoted) as $generation) {
            $this->host->deleteSnapshotsIfExist($this->snapshotMap($generation));
            $this->manifests->forget($generation);
        }

        $protected = [];
        foreach ([$promoted, ...$this->manifests->recorded()] as $generation) {
            foreach ($this->snapshotMap($generation) as $instance => $snapshot) {
                $protected[$instance][$snapshot] = true;
            }
        }

        $instances = array_map(TopologyTarget::standby($this->identity)->instance(...), TopologyProfile::ROLES);
        $inventory = $this->host->ownedSnapshotNames($instances);
        $deletions = [];
        foreach ($inventory as $instance => $snapshots) {
            foreach ($snapshots as $snapshotData) {
                $snapshot = $snapshotData['name'];
                if (
                    preg_match('/\Amain-[a-z0-9-]+\z/D', $snapshot) === 1
                    && ! isset($protected[$instance][$snapshot])
                ) {
                    $deletions[$snapshot]['snapshots'][$instance] = $snapshot;
                    $deletions[$snapshot]['created_at'][$instance] = $snapshotData['created_at'];
                }
            }
        }
        $ordered = [];
        foreach ($deletions as $name => $candidate) {
            $times = array_map(
                strtotime(...),
                array_values($candidate['created_at'] ?? []),
            );
            if ($times === [] || in_array(false, $times, true)) {
                throw new RuntimeException('Incus snapshot creation metadata is missing or invalid.');
            }
            $ordered[$name] = max($times);
        }
        if (count($ordered) !== count(array_unique($ordered, SORT_NUMERIC))) {
            throw new RuntimeException('Incus snapshot creation metadata has duplicate ordering values.');
        }
        uksort($ordered, static fn (string $a, string $b): int => $ordered[$b] <=> $ordered[$a]);
        foreach (array_keys($ordered) as $name) {
            $this->host->deleteSnapshotsIfExist($deletions[$name]['snapshots']);
        }
    }

    /** @return array<string, string> */
    private function snapshotMap(StandbyGeneration $generation): array
    {
        $snapshots = [];
        $target = TopologyTarget::standby($this->identity);
        foreach (TopologyProfile::ROLES as $role) {
            $snapshots[$target->instance($role)] = $generation->snapshots[$role];
        }

        return $snapshots;
    }

    private function prune(StandbyGeneration $current): void
    {
        try {
            foreach ($this->manifests->prunable($current) as $generation) {
                $snapshots = [];
                foreach (TopologyProfile::ROLES as $role) {
                    $snapshots[TopologyTarget::standby($this->identity)->instance($role)] =
                        $generation->snapshots[$role];
                }
                $this->host->deleteSnapshotsIfExist($snapshots);
                $this->manifests->forget($generation);
            }
        } catch (Throwable) {
            // Uncertain or failed pruning never invalidates the promoted generation.
        }
    }

    private function markCorrupt(Throwable $exception): void
    {
        $this->state->write('standby/corrupt.json', [
            'schema' => 2,
            'operation_id' => $this->operation->value,
            'message' => $exception->getMessage(),
        ]);
    }
}
