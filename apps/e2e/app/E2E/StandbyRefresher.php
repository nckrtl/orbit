<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Git\GitRepository;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\OperationJournal;
use App\E2E\State\OperationLock;
use App\E2E\Value\GuestCommand;
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
        private RefreshRequestStore $requests,
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
        $this->requests->request($mainSha);
        if (! $this->lock->acquire('standby-refresh', $operation, timeoutSeconds: 5.0)) {
            return new RefreshResult('queued', $operation->value, $evidence);
        }

        try {
            try {
                $result = new RefreshResult('unchanged', $operation->value, $evidence);
                while (($pending = $this->requests->pending()) !== null) {
                    $result = $this->refresh(
                        $pending,
                        $pending === $mainSha ? $migration : null,
                        $allowCold,
                        $operation,
                        $evidence,
                    );
                    if ($result->state === 'failed') {
                        return $result;
                    }
                }

                return $result;
            } catch (Throwable $exception) {
                $this->state->write("standby/failures/{$evidence}.json", [
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
        $generation = $this->manifests->promoted();
        if ($generation === null) {
            throw new RuntimeException('There is no promoted standby generation.');
        }

        $this->stopAndProve();
        $this->restoreSnapshots($generation);
        $this->assertStopped();

        return $generation;
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

        $desired = $this->fingerprints->forCommit($mainSha);
        $alias = $desired->manifest['base_image_alias'] ?? null;
        if (! is_string($alias) || $alias === '') {
            throw new RuntimeException('The prepared fingerprint has no base image alias.');
        }
        $baseImageFingerprint = $this->host->imageFingerprint($alias);
        try {
            $promoted = $this->manifests->promoted();
        } catch (Throwable $exception) {
            $this->markCorrupt($evidence, $exception);
            throw $exception;
        }

        if (
            $promoted !== null
            && $promoted->preparedFingerprint === $desired->value
            && $promoted->baseImageFingerprint === $baseImageFingerprint
        ) {
            $this->requests->clear($mainSha);

            return new RefreshResult('unchanged', $operation->value, $evidence, $promoted->id);
        }

        $release = $this->laravel->resolve('^13.0');
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
                $promoted?->id,
            );
            $this->manifests->record($generation);
            $this->manifests->promote($generation);
            $this->prune($generation);
            $this->requests->clear($mainSha);

            return new RefreshResult('promoted', $operation->value, $evidence, $generation->id);
        } catch (Throwable $exception) {
            $this->state->write("standby/failures/{$evidence}.json", [
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
        $target = TopologyTarget::standby();
        foreach (TopologyProfile::ROLES as $role) {
            $this->host->restore($target->instance($role), $generation->snapshots[$role]);
        }
    }

    private function startAll(): void
    {
        $target = TopologyTarget::standby();
        foreach (TopologyProfile::ROLES as $role) {
            $this->host->start($target->instance($role));
        }
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

    private function prune(StandbyGeneration $current): void
    {
        try {
            foreach ($this->manifests->prunable($current) as $generation) {
                foreach (TopologyProfile::ROLES as $role) {
                    $this->host->deleteSnapshot(
                        TopologyTarget::standby()->instance($role),
                        $generation->snapshots[$role],
                    );
                }
                $this->manifests->forget($generation);
            }
        } catch (Throwable) { // @mago-expect lint:no-empty-catch-clause Conservative pruning must not invalidate promotion.
            // Uncertain or failed pruning never invalidates the promoted generation.
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
