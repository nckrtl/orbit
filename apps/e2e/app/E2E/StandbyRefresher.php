<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Git\GitRepository;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\OperationJournal;
use App\E2E\State\OperationLock;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\MigrationPlan;
use App\E2E\Value\OperationId;
use App\E2E\Value\RefreshResult;
use App\E2E\Value\StandbyGeneration;
use App\E2E\Value\SyncMode;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationMode;
use RuntimeException;
use Throwable;

/** @mago-expect lint:excessive-parameter-list,cyclomatic-complexity,kan-defect,too-many-methods Explicit workflow dependencies preserve the promotion boundary. */
final readonly class StandbyRefresher
{
    public function __construct(
        private IncusHost $host,
        private PreparedStateFingerprint $fingerprints,
        private StandbyManifestStore $manifests,
        private StandbyBuilder $builder,
        private WorktreeSynchronizer $synchronizer,
        private TopologyConverger $converger,
        private TopologyVerifier $verifier,
        private LaravelReleaseResolver $laravel,
        private OperationLock $lock,
        private OperationJournal $journal,
        private AtomicJsonStore $state,
        private GitRepository $git,
        private string $mainWorktree,
    ) {}

    public function request(string $mainSha, ?MigrationPlan $migration = null, bool $allowCold = false): RefreshResult
    {
        if (preg_match('/\A[a-f0-9]{40}\z/D', $mainSha) !== 1) {
            throw new RuntimeException('The refresh SHA is invalid.');
        }

        $operation = new OperationId(bin2hex(random_bytes(16)));
        $evidence = bin2hex(random_bytes(16));
        if (! $this->lock->acquire('standby-generation', $operation, timeoutSeconds: 5.0)) {
            $this->writeFailureIfMissing("standby/failures/{$evidence}.json", [
                'schema' => 1,
                'main_sha' => $mainSha,
                'message' => 'Unable to acquire the standby generation lock.',
            ]);

            return new RefreshResult('failed', $operation->value, $evidence);
        }

        try {
            try {
                return $this->refresh($mainSha, $migration, $allowCold, $operation, $evidence);
            } catch (Throwable $exception) {
                $this->writeFailureIfMissing("standby/failures/{$evidence}.json", [
                    'schema' => 1,
                    'main_sha' => $mainSha,
                    'message' => $exception->getMessage(),
                ]);

                return new RefreshResult('failed', $operation->value, $evidence);
            }
        } finally {
            $this->lock->release();
        }
    }

    public function restore(): StandbyGeneration
    {
        $operation = new OperationId(bin2hex(random_bytes(16)));
        if (! $this->lock->acquire('standby-generation', $operation, timeoutSeconds: 3600)) {
            throw new RuntimeException('Unable to acquire the standby generation lock.');
        }

        try {
            $generation = $this->manifests->promoted();
            if ($generation === null) {
                throw new RuntimeException('There is no promoted standby generation.');
            }

            $this->stopAndProve();
            $this->restoreSnapshots($generation);
            $this->assertStopped();
            $this->state->delete('standby/corrupt.json');

            return $generation;
        } finally {
            $this->lock->release();
        }
    }

    private function refresh(
        string $mainSha,
        ?MigrationPlan $migration,
        bool $allowCold,
        OperationId $operation,
        string $evidence,
    ): RefreshResult {
        if ($this->git->commit('HEAD') !== $mainSha || $this->git->dirtyOverlay() !== null) {
            throw new RuntimeException('The host main checkout does not match the requested clean SHA.');
        }

        try {
            $promoted = $this->manifests->promoted();
        } catch (Throwable $exception) {
            $this->markCorrupt($evidence, $exception);
            throw $exception;
        }
        $desired = $this->fingerprints->forCommit($mainSha, $promoted?->laravel);
        $alias = $desired->manifest['base_image_alias'] ?? null;
        if (! is_string($alias) || $alias === '') {
            throw new RuntimeException('The prepared fingerprint has no base image alias.');
        }
        $baseImageFingerprint = $this->host->imageFingerprint($alias);
        if (
            $promoted !== null
            && $promoted->preparedFingerprint === $desired->value
            && $promoted->baseImageFingerprint === $baseImageFingerprint
        ) {
            $this->assertGenerationAvailable($promoted);
            $this->assertStopped();

            return new RefreshResult('unchanged', $operation->value, $evidence, $promoted->id);
        }

        $release = $this->laravel->resolve('>=13.0.0');
        $desired = $this->fingerprints->forCommit($mainSha, $release);
        $target = TopologyTarget::standby();

        try {
            if ($promoted === null) {
                $source = $this->builder->build(
                    $mainSha,
                    $desired,
                    $baseImageFingerprint,
                    $release,
                    $allowCold,
                    $evidence,
                );
            } else {
                if ($promoted->baseImageFingerprint !== $baseImageFingerprint) {
                    throw new RuntimeException('The promoted base image fingerprint drifted.');
                }
                $this->assertStopped();
                $this->restoreSnapshots($promoted);
                $this->startAll();
                $source = $this->synchronizer->sync($target, $this->mainWorktree, SyncMode::Full);
                if ($source->dirty || $source->hostSha !== $mainSha || $source->guestSha !== $mainSha) {
                    throw new RuntimeException('Standby source is not clean merged main.');
                }
                $this->converger->converge($target, $source, $release);
            }

            $this->migrate($target, $migration, $desired->value, $operation);
            $verification = $this->verifier->verify($target, VerificationMode::Readiness, $source);
            if (! $verification->passed) {
                throw new RuntimeException('Standby verification failed.');
            }
            $proof = $this->verifier->verify($target, VerificationMode::Proof, $source);
            if (! $proof->passed) {
                throw new RuntimeException('Standby proof verification failed.');
            }
            $this->stopAndProve();
            $this->state->write("standby/evidence/{$evidence}.json", [
                'readiness' => $verification->toArray(),
                'proof' => $proof->toArray(),
                'stopped' => true,
            ]);
            $generation = $this->snapshot(
                $mainSha,
                $desired->value,
                $baseImageFingerprint,
                $release,
                $promoted?->id,
            );
            $this->manifests->record($generation);
            $this->manifests->promote($generation);
            $this->prune($generation, $evidence);

            return new RefreshResult('promoted', $operation->value, $evidence, $generation->id);
        } catch (Throwable $exception) {
            $this->writeFailureIfMissing("standby/failures/{$evidence}.json", [
                'schema' => 1,
                'main_sha' => $mainSha,
                'message' => $exception->getMessage(),
            ]);
            $recovered = $this->rollback($promoted, $evidence);
            $this->state->write("standby/recovery/{$evidence}.json", [
                'schema' => 1,
                'recovered' => $recovered,
                'stopped' => $recovered,
                'generation_id' => $promoted?->id,
            ]);

            return new RefreshResult('failed', $operation->value, $evidence, $promoted?->id);
        }
    }

    private function restoreSnapshots(StandbyGeneration $generation): void
    {
        $this->assertGenerationAvailable($generation);
        $target = TopologyTarget::standby();
        foreach (TopologyProfile::ROLES as $role) {
            $this->host->restore($target->instance($role), $generation->snapshots[$role]);
        }
    }

    private function assertGenerationAvailable(StandbyGeneration $generation): void
    {
        $target = TopologyTarget::standby();
        foreach (TopologyProfile::ROLES as $role) {
            $this->host->assertOwnedSnapshot($target->instance($role), $generation->snapshots[$role]);
        }
    }

    /** @param array<array-key, mixed> $failure */
    private function writeFailureIfMissing(string $path, array $failure): void
    {
        if ($this->state->read($path) !== null) {
            return;
        }

        $this->state->write($path, $failure);
    }

    private function startAll(): void
    {
        $target = TopologyTarget::standby();
        foreach (TopologyProfile::ROLES as $role) {
            $this->host->start($target->instance($role));
        }
        $this->host->waitForAgents(array_map(
            $target->instance(...),
            TopologyProfile::ROLES,
        ));
        $this->host->waitForGlobalIpv4(array_map($target->instance(...), TopologyProfile::ROLES));
    }

    private function stopAll(): void
    {
        $target = TopologyTarget::standby();
        foreach (array_reverse(TopologyProfile::ROLES) as $role) {
            $instance = $this->host->instance($target->instance($role));
            if ($instance === null) {
                throw new RuntimeException('A standby VM is missing while stopping the topology.');
            }
            if ($instance->isRunning()) {
                $this->host->stop($instance->name);
            }
        }
    }

    private function stopAndProve(): void
    {
        $this->stopAll();
        $this->assertStopped();
    }

    private function assertStopped(): void
    {
        $target = TopologyTarget::standby();
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $allStopped = true;
            foreach (TopologyProfile::ROLES as $role) {
                $instance = $this->host->instance($target->instance($role));
                if ($instance === null) {
                    throw new RuntimeException('A standby VM is missing while checking its power state.');
                }
                $allStopped = $allStopped && $instance->isStopped();
            }
            if ($allStopped) {
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
    ): StandbyGeneration {
        $id = substr($mainSha, 0, 12).'-'.substr($fingerprint, 0, 12);
        $snapshot = 'main-'.$id;
        $snapshots = [];
        $target = TopologyTarget::standby();
        try {
            foreach (TopologyProfile::ROLES as $role) {
                $this->host->snapshot($target->instance($role), $snapshot);
                $snapshots[$role] = $snapshot;
            }
        } catch (Throwable $exception) {
            foreach ($snapshots as $role => $created) {
                $this->host->deleteSnapshot($target->instance($role), $created);
            }
            throw $exception;
        }

        return new StandbyGeneration(
            $id,
            $mainSha,
            $snapshots,
            $fingerprint,
            $baseImageFingerprint,
            $laravel,
            $previousGenerationId,
        );
    }

    private function migrate(
        TopologyTarget $target,
        ?MigrationPlan $migration,
        string $fingerprint,
        OperationId $operation,
    ): void {
        if ($migration === null) {
            return;
        }
        if ($migration->fingerprint !== $fingerprint) {
            throw new RuntimeException('The migration fingerprint does not match the desired state.');
        }

        foreach ($migration->steps as $step) {
            $result = $this->host->exec(
                $target->instance($step['role']),
                new GuestCommand($step['argv'], 900, $step['stdin']),
            );
            $this->journal->append($operation, [
                'step' => 'migration',
                'role' => $step['role'],
                'argv' => $step['argv'],
                'exit_code' => $result->exitCode,
            ]);
            if (! $result->successful()) {
                throw new RuntimeException('A standby migration step failed.');
            }
        }
    }

    private function rollback(?StandbyGeneration $generation, string $evidence): bool
    {
        if ($generation === null) {
            return $this->builder->cleanupCold($evidence);
        }

        try {
            $this->stopAndProve();
            $this->restoreSnapshots($generation);
            $this->assertStopped();

            return true;
        } catch (Throwable $exception) {
            $this->markCorrupt($evidence, $exception);

            return false;
        }
    }

    private function prune(StandbyGeneration $current, string $evidence): void
    {
        try {
            foreach ($this->manifests->prunable($current) as $generation) {
                foreach (TopologyProfile::ROLES as $role) {
                    $this->host->deleteSnapshotIfExists(
                        TopologyTarget::standby()->instance($role),
                        $generation->snapshots[$role],
                    );
                }
                $this->manifests->forget($generation);
            }
        } catch (Throwable $exception) {
            // Uncertain or failed pruning never invalidates the promoted generation.
            $this->writeFailureIfMissing("standby/failures/{$evidence}.json", [
                'schema' => 1,
                'phase' => 'pruning',
                'generation_id' => $current->id,
                'main_sha' => $current->mainSha,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function markCorrupt(string $evidence, Throwable $exception): void
    {
        $this->state->write('standby/corrupt.json', [
            'schema' => 1,
            'evidence_id' => $evidence,
            'message' => $exception->getMessage(),
        ]);
    }
}
