<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Git\GitRepository;
use App\E2E\State\OperationLock;
use App\E2E\State\SecretRedactor;
use App\E2E\Value\AttemptId;
use App\E2E\Value\CapturedProof;
use App\E2E\Value\ColdTopologyPlan;
use App\E2E\Value\IncusInstance;
use App\E2E\Value\OperationId;
use App\E2E\Value\ProofInputManifest;
use App\E2E\Value\ProofPromotionRecord;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologyRequest;
use App\E2E\Value\TopologySnapshotGeneration;
use App\E2E\Value\TopologySnapshotIdentity;
use App\E2E\Value\TopologySnapshotReplacementInstallation;
use App\E2E\Value\TopologySnapshotReplacementRecovery;
use App\E2E\Value\TopologySnapshotReplacementResult;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationMode;
use Closure;
use RuntimeException;
use Throwable;

/** Construct and transactionally install a clean declared snapshot replacement. */
final readonly class TopologySnapshotReplacementInstaller
{
    private const array ATTEMPT_METADATA = [
        'user.orbit.e2e.issue',
        'user.orbit.e2e.attempt',
        'user.orbit.e2e.generation',
    ];

    public function __construct(
        private IncusHost $host,
        private ColdTopologyConstructor $constructor,
        private PreparedStateFingerprint $fingerprints,
        private TopologyVerifier $verifier,
        private TopologySnapshotManifestStore $manifests,
        private TopologySnapshotPromotionStore $promotions,
        private TopologySnapshotReplacementStore $replacements,
        private OperationLock $refreshLock,
        private OperationLock $generationLock,
        private GitRepository $primary,
        private OperationId $operation,
        private TopologySnapshotIdentity $identity,
        private string $mainWorktree,
        private SecretRedactor $redactor,
        /** @var (Closure(): AttemptId)|null */
        private ?Closure $attempts = null,
    ) {}

    public function install(
        TopologyRequest $request,
        CapturedProof $capture,
        string $candidateSha,
        string $artifactSha,
        string $mergeSha,
        string $mainSha,
    ): TopologySnapshotReplacementResult {
        $nextAction = $this->nextAction($request, $candidateSha, $artifactSha, $mergeSha, $mainSha);
        try {
            $this->assertCapture($request, $capture);
            if (! $this->refreshLock->acquire('standby-refresh', $this->operation, timeoutSeconds: 3600)) {
                throw new RuntimeException('Unable to acquire the topology snapshot refresh lock.');
            }
            try {
                $recovery = $this->replacements->active();
                if ($recovery === null) {
                    $recovery = $this->replacements->start($this->installation(
                        $request,
                        $capture,
                        $candidateSha,
                        $artifactSha,
                        $mergeSha,
                        $mainSha,
                    ));
                } else {
                    $this->assertRetryIdentity(
                        $recovery->installation,
                        $request,
                        $capture,
                        $candidateSha,
                        $artifactSha,
                        $mergeSha,
                        $mainSha,
                    );
                }

                return $this->resume($recovery, $nextAction);
            } finally {
                $this->refreshLock->release();
            }
        } catch (Throwable $exception) {
            $active = $this->replacements->active();
            if ($active === null) {
                return new TopologySnapshotReplacementResult(
                    'failed',
                    $this->operation->value,
                    null,
                    'validation',
                    $this->redactor->redact($exception->getMessage()),
                    $nextAction,
                );
            }

            return new TopologySnapshotReplacementResult(
                $active->phase === 'recovery_required' ? 'recovery-required' : 'failed',
                $active->installation->resourceOperation->value,
                $active->installation->newGeneration->id,
                $active->phase,
                $this->redactor->redact($exception->getMessage()),
                $active->nextAction,
            );
        }
    }

    public function abandon(TopologyRequest $request, CapturedProof $capture): TopologySnapshotReplacementResult
    {
        $recovery = $this->replacements->active();
        if ($recovery === null) {
            return new TopologySnapshotReplacementResult('abandoned', $this->operation->value, null, 'abandoned');
        }
        if (
            $recovery->installation->issue !== $request->issue
            || $recovery->installation->proofAttempt->value !== $capture->attempt->value
        ) {
            throw new RuntimeException('The active snapshot replacement belongs to another retained proof.');
        }
        if ($recovery->manifestPromoted || $this->isPromoted($recovery->installation->newGeneration)) {
            throw new RuntimeException('An installed snapshot replacement must finish forward cleanup before abandonment.');
        }
        if (in_array($recovery->phase, ['construction_pending', 'construction_verified', 'staging_pending', 'staging_verified'], true)) {
            $recovery = $this->failurePhase(
                $recovery,
                'preparation_failed',
                'The clean snapshot replacement was explicitly abandoned.',
                $recovery->nextAction,
            );
            $recovery = $this->phase(
                $recovery,
                'cleanup_pending',
                $recovery->error,
                $recovery->nextAction,
            );
        } elseif (in_array($recovery->phase, ['swap_pending', 'swap_in_progress', 'manifest_pending', 'rollback_pending', 'recovery_required'], true)) {
            $recovery = $this->rollback($recovery);
        } elseif (! in_array($recovery->phase, ['authorized', 'preparation_failed', 'rolled_back', 'cleanup_pending'], true)) {
            throw new RuntimeException('The snapshot replacement cannot be abandoned from its retained phase.');
        }
        if ($recovery->phase === 'preparation_failed') {
            $recovery = $this->phase(
                $recovery,
                'cleanup_pending',
                $recovery->error,
                $recovery->nextAction,
            );
        }
        $recovery = $this->cleanupPrepared($recovery);
        $recovery = $this->phase($recovery, 'abandoned');
        $this->replacements->complete($recovery);

        return new TopologySnapshotReplacementResult(
            'abandoned',
            $recovery->installation->resourceOperation->value,
            null,
            'abandoned',
        );
    }

    private function resume(
        TopologySnapshotReplacementRecovery $recovery,
        string $nextAction,
    ): TopologySnapshotReplacementResult {
        if (in_array($recovery->phase, ['construction_pending', 'staging_pending'], true)) {
            return $this->discardInterruptedPreparation($recovery, $nextAction);
        }
        if ($recovery->phase === 'rollback_pending') {
            return $this->finishRollback($recovery, $nextAction);
        }
        if (in_array($recovery->phase, ['swap_pending', 'swap_in_progress', 'manifest_pending'], true)) {
            if ($this->isPromoted($recovery->installation->newGeneration)) {
                $recovery = $this->recordCommittedManifest($recovery);
            } else {
                $recovery = $this->rollback($recovery);
                $recovery = $this->cleanupPrepared($recovery);
                $recovery = $this->phase($recovery, 'abandoned');
                $this->replacements->complete($recovery);

                return new TopologySnapshotReplacementResult(
                    'failed',
                    $recovery->installation->resourceOperation->value,
                    $recovery->installation->oldGeneration->id,
                    'rolled_back',
                    'An interrupted replacement was rolled back; retry closeout to reconstruct it.',
                    $nextAction,
                );
            }
        }

        if (in_array($recovery->phase, ['preparation_failed', 'rolled_back'], true)) {
            return $this->finishRollback($recovery, $nextAction);
        }
        if (in_array($recovery->phase, ['cleanup_pending', 'recovery_required'], true)) {
            if ($recovery->manifestPromoted || $this->isPromoted($recovery->installation->newGeneration)) {
                return $this->finishCommittedCleanup($recovery, $nextAction);
            }

            return $this->finishRollback($recovery, $nextAction);
        }

        try {
            if ($recovery->phase === 'authorized') {
                $recovery = $this->phase($recovery, 'construction_pending');
                $this->construct($recovery->installation);
                $recovery = $this->phase($recovery, 'construction_verified');
            }
            if ($recovery->phase === 'construction_verified') {
                $recovery = $this->phase($recovery, 'staging_pending');
                $this->stage($recovery->installation);
                $recovery = $this->phase($recovery, 'staging_verified');
            }
            if ($recovery->phase === 'staging_verified') {
                $recovery = $this->phase($recovery, 'swap_pending');
                $recovery = $this->swap($recovery);
            }
            if (in_array($recovery->phase, [
                'manifest_promoted',
                'old_cleanup_pending',
                'old_cleanup_verified',
                'temporary_cleanup_pending',
            ], true)) {
                $recovery = $this->cleanupInstalled($recovery);
            }
            if ($recovery->phase === 'temporary_cleanup_verified') {
                $recovery = $this->phase($recovery, 'complete');
                $this->replacements->complete($recovery);
            }
            if ($recovery->phase !== 'complete') {
                throw new RuntimeException(
                    'Snapshot replacement did not reach archived complete state; retry its recorded next action.',
                );
            }

            return new TopologySnapshotReplacementResult(
                'installed',
                $recovery->installation->resourceOperation->value,
                $recovery->installation->newGeneration->id,
                $recovery->phase,
            );
        } catch (Throwable $exception) {
            return $this->recoverFailure($recovery, $exception, $nextAction);
        }
    }

    private function discardInterruptedPreparation(
        TopologySnapshotReplacementRecovery $recovery,
        string $nextAction,
    ): TopologySnapshotReplacementResult {
        $message = 'An interrupted replacement preparation was cleaned; retry closeout to reconstruct it.';
        $recovery = $this->failurePhase($recovery, 'preparation_failed', $message, $nextAction);
        $recovery = $this->phase($recovery, 'cleanup_pending', $message, $nextAction);
        try {
            $recovery = $this->cleanupPrepared($recovery);
            $recovery = $this->phase($recovery, 'abandoned');
            $this->replacements->complete($recovery);
        } catch (Throwable $exception) {
            $message .= ' '.$this->redactor->redact($exception->getMessage());
            $recovery = $this->failurePhase($recovery, 'recovery_required', $message, $nextAction);
        }

        return new TopologySnapshotReplacementResult(
            $recovery->phase === 'recovery_required' ? 'recovery-required' : 'failed',
            $recovery->installation->resourceOperation->value,
            $recovery->installation->oldGeneration->id,
            $recovery->phase,
            $message,
            $nextAction,
        );
    }

    private function finishRollback(
        TopologySnapshotReplacementRecovery $recovery,
        string $nextAction,
    ): TopologySnapshotReplacementResult {
        $message = $recovery->error ?? 'The interrupted replacement must restore the prior generation.';
        try {
            if (! in_array($recovery->phase, ['rolled_back', 'preparation_failed', 'cleanup_pending'], true)) {
                $recovery = $this->rollback($recovery);
            }
            if ($recovery->phase === 'preparation_failed') {
                $recovery = $this->phase($recovery, 'cleanup_pending', $message, $nextAction);
            }
            $recovery = $this->cleanupPrepared($recovery);
            $recovery = $this->phase($recovery, 'abandoned');
            $this->replacements->complete($recovery);
        } catch (Throwable $exception) {
            $message .= ' '.$this->redactor->redact($exception->getMessage());
            if ($recovery->phase !== 'recovery_required') {
                $recovery = $this->failurePhase($recovery, 'recovery_required', $message, $nextAction);
            }
        }

        return new TopologySnapshotReplacementResult(
            $recovery->phase === 'recovery_required' ? 'recovery-required' : 'failed',
            $recovery->installation->resourceOperation->value,
            $recovery->installation->oldGeneration->id,
            $recovery->phase,
            $message,
            $nextAction,
        );
    }

    private function finishCommittedCleanup(
        TopologySnapshotReplacementRecovery $recovery,
        string $nextAction,
    ): TopologySnapshotReplacementResult {
        try {
            $recovery = $this->cleanupFromFailure($recovery);
            $recovery = $this->phase($recovery, 'complete');
            $this->replacements->complete($recovery);

            return new TopologySnapshotReplacementResult(
                'installed',
                $recovery->installation->resourceOperation->value,
                $recovery->installation->newGeneration->id,
                'complete',
            );
        } catch (Throwable $exception) {
            $message = $this->redactor->redact($exception->getMessage());
            if ($recovery->phase !== 'recovery_required') {
                $recovery = $this->failurePhase($recovery, 'recovery_required', $message, $nextAction);
            }

            return new TopologySnapshotReplacementResult(
                'recovery-required',
                $recovery->installation->resourceOperation->value,
                $recovery->installation->newGeneration->id,
                $recovery->phase,
                $message,
                $nextAction,
            );
        }
    }

    private function recoverFailure(
        TopologySnapshotReplacementRecovery $recovery,
        Throwable $exception,
        string $nextAction,
    ): TopologySnapshotReplacementResult {
        $message = $this->redactor->redact($exception->getMessage());
        try {
            $retained = $this->replacements->active();
            if ($retained !== null) {
                if (! $retained->installation->sameIdentity($recovery->installation)) {
                    throw new RuntimeException('Snapshot replacement recovery identity changed after failure.');
                }
                $recovery = $retained;
            }
            if ($recovery->manifestPromoted || $this->isPromoted($recovery->installation->newGeneration)) {
                if (in_array($recovery->phase, ['swap_pending', 'swap_in_progress', 'manifest_pending'], true)) {
                    $recovery = $this->recordCommittedManifest($recovery);
                } else {
                    $this->recordPromotionLineage($recovery->installation);
                }
                if ($recovery->phase !== 'cleanup_pending') {
                    $recovery = $this->failurePhase($recovery, 'cleanup_pending', $message, $nextAction);
                }
            } elseif (in_array($recovery->phase, ['swap_pending', 'swap_in_progress', 'manifest_pending'], true)) {
                $recovery = $this->failurePhase($recovery, 'rollback_pending', $message, $nextAction);
                $recovery = $this->rollback($recovery);
                $recovery = $this->cleanupPrepared($recovery);
                $recovery = $this->phase($recovery, 'abandoned');
                $this->replacements->complete($recovery);
            } else {
                $recovery = $this->failurePhase($recovery, 'preparation_failed', $message, $nextAction);
                $recovery = $this->phase($recovery, 'cleanup_pending', $message, $nextAction);
                $recovery = $this->cleanupPrepared($recovery);
                $recovery = $this->phase($recovery, 'abandoned');
                $this->replacements->complete($recovery);
            }
        } catch (Throwable $recoveryFailure) {
            $message .= ' Recovery failed: '.$this->redactor->redact($recoveryFailure->getMessage());
            if ($recovery->phase !== 'recovery_required') {
                $recovery = $this->failurePhase($recovery, 'recovery_required', $message, $nextAction);
            }
        }

        return new TopologySnapshotReplacementResult(
            $recovery->phase === 'recovery_required' ? 'recovery-required' : 'failed',
            $recovery->installation->resourceOperation->value,
            $recovery->installation->newGeneration->id,
            $recovery->phase,
            $message,
            $nextAction,
        );
    }

    private function construct(TopologySnapshotReplacementInstallation $installation): void
    {
        $target = $this->temporaryTarget($installation);
        $construction = $this->constructor->constructReplacement(new ColdTopologyPlan(
            $target,
            $this->mainWorktree,
            $installation->mainSha,
            [$installation->genericImageAlias => $installation->genericImageFingerprint],
            $installation->newGeneration->laravel,
            $installation->resourceOperation,
            [
                'user.orbit.e2e.operation' => $installation->resourceOperation->value,
                'user.orbit.e2e.issue' => $installation->issue,
                'user.orbit.e2e.attempt' => $installation->replacementAttempt->value,
            ],
            snapshotReplacement: true,
        ));
        if (! $construction->snapshotReplacement || $construction->sourceGeneration !== 'generic-base') {
            throw new RuntimeException('Clean replacement construction lost its generic-base declaration.');
        }
        $source = new SourceState(
            $installation->mainSha,
            $installation->mainSha,
            operationId: $installation->resourceOperation->value,
        );
        foreach ([VerificationMode::Readiness, VerificationMode::Proof] as $mode) {
            $report = $this->verifier->verify(
                $target,
                $mode,
                $source,
                requiredAssignments: TopologyProfile::ASSIGNMENTS,
                nativeSamplesOnly: true,
            );
            if (! $report->passed) {
                throw new RuntimeException('Clean replacement verification failed.'.$report->failedSummary());
            }
        }
        $this->host->stopAll(array_values($installation->temporaryInstances));
        $this->assertStopped(array_values($installation->temporaryInstances), 'temporary replacement');
    }

    private function stage(TopologySnapshotReplacementInstallation $installation): void
    {
        if ($this->host->instances([...array_values($installation->nextInstances), ...array_values($installation->oldInstances)]) !== []) {
            throw new RuntimeException('A snapshot replacement staging or rollback VM already exists.');
        }
        $copies = [];
        foreach (TopologyProfile::ROLES as $role) {
            $copies[$role] = [
                'source' => $installation->temporaryInstances[$role],
                'target' => $installation->nextInstances[$role],
                'metadata' => ['user.orbit.e2e.operation' => $installation->resourceOperation->value],
                'network' => $this->identity->network(),
                'role' => $role,
                'topology' => $this->identity->network(),
                'slot' => $this->identity->slot,
            ];
        }
        $this->host->copyInstances($copies);
        foreach ($installation->nextInstances as $instance) {
            $this->host->unsetMetadata($instance, self::ATTEMPT_METADATA);
        }
        $snapshots = [];
        foreach (TopologyProfile::ROLES as $role) {
            $snapshots[$installation->nextInstances[$role]] = $installation->newGeneration->snapshots[$role];
        }
        $this->host->snapshotAll($snapshots);
        $this->host->assertOwnedSnapshots($snapshots);
        $this->assertReplacementInstances($installation, $installation->nextInstances);
    }

    private function swap(TopologySnapshotReplacementRecovery $recovery): TopologySnapshotReplacementRecovery
    {
        $installation = $recovery->installation;
        if (! $this->generationLock->acquire('standby-generation', $this->operation, timeoutSeconds: 3600)) {
            throw new RuntimeException('Unable to acquire the topology snapshot generation lock.');
        }
        try {
            if ($this->manifests->promoted()?->toArray() !== $installation->oldGeneration->toArray()) {
                throw new RuntimeException('The promoted generation changed before replacement installation.');
            }
            $this->assertOldGeneration($installation);
            $this->assertReplacementInstances($installation, $installation->nextInstances);
            $recovery = $this->phase($recovery, 'swap_in_progress');
            foreach (TopologyProfile::ROLES as $role) {
                $recovery = $recovery->withOldRename($role);
                $this->replacements->advance($recovery);
                $this->host->renameInstance($installation->canonicalInstances[$role], $installation->oldInstances[$role]);
                $recovery = $recovery->withNewRename($role);
                $this->replacements->advance($recovery);
                $this->host->renameInstance($installation->nextInstances[$role], $installation->canonicalInstances[$role]);
            }
            $this->assertReplacementInstances($installation, $installation->canonicalInstances);
            $recovery = $this->phase($recovery, 'manifest_pending');
            $this->manifests->record($installation->newGeneration);
            $recovery = $recovery->withGenerationRecorded();
            $this->replacements->advance($recovery);
            $this->manifests->promote($installation->newGeneration);
            $recovery = $recovery->withManifestPromoted();
            $this->replacements->advance($recovery);
            $this->recordPromotionLineage($installation);

            return $this->phase($recovery, 'manifest_promoted');
        } finally {
            $this->generationLock->release();
        }
    }

    private function cleanupInstalled(
        TopologySnapshotReplacementRecovery $recovery,
    ): TopologySnapshotReplacementRecovery {
        $installation = $recovery->installation;
        if ($recovery->phase === 'manifest_promoted') {
            $recovery = $this->phase($recovery, 'old_cleanup_pending');
        }
        if ($recovery->phase === 'old_cleanup_pending') {
            foreach (TopologyProfile::ROLES as $role) {
                $name = $installation->oldInstances[$role];
                $instance = $this->host->instances([$name])[$name] ?? null;
                if ($instance !== null) {
                    $this->assertOldInstance($installation, $role, $instance, $name);
                }
                if (! in_array($role, $recovery->deletedOldRoles, true)) {
                    $recovery = $recovery->withOldDeleted($role);
                    $this->replacements->advance($recovery);
                }
                if ($instance !== null) {
                    $this->host->deleteInstances([$name]);
                }
            }
            foreach ($this->manifests->recorded() as $generation) {
                if ($generation->id === $installation->oldGeneration->id) {
                    $this->manifests->forget($generation);
                }
            }
            $recovery = $this->phase($recovery, 'old_cleanup_verified');
        }
        if ($recovery->phase === 'old_cleanup_verified') {
            $recovery = $this->phase($recovery, 'temporary_cleanup_pending');
        }
        if ($recovery->phase === 'temporary_cleanup_pending') {
            $cleanup = $this->constructor->cleanup(
                $this->temporaryTarget($installation),
                $installation->resourceOperation,
            );
            if (! $cleanup->successful()) {
                throw new RuntimeException(
                    'Clean replacement temporary cleanup failed: '.implode('; ', $cleanup->refused),
                );
            }
            $recovery = $recovery->withTemporaryResourcesCleaned()->withStagedResourcesCleaned();
            $this->replacements->advance($recovery);
            $this->assertReplacementInstances($installation, $installation->canonicalInstances);

            return $this->phase($recovery, 'temporary_cleanup_verified');
        }

        return $recovery;
    }

    /** Complete exact post-commit cleanup from a failure state without replaying completed mutations. */
    private function cleanupFromFailure(
        TopologySnapshotReplacementRecovery $recovery,
    ): TopologySnapshotReplacementRecovery {
        if ($recovery->phase === 'recovery_required') {
            $recovery = $this->phase(
                $recovery,
                'cleanup_pending',
                $recovery->error ?? 'Replacement cleanup is incomplete.',
                $recovery->nextAction,
            );
        }
        if ($recovery->phase !== 'cleanup_pending') {
            throw new RuntimeException('Replacement cleanup cannot resume from its retained phase.');
        }
        $installation = $recovery->installation;
        foreach (TopologyProfile::ROLES as $role) {
            if (! in_array($role, $recovery->oldRenamedRoles, true)) {
                $recovery = $recovery->withOldRename($role);
            }
            if (! in_array($role, $recovery->newRenamedRoles, true)) {
                $recovery = $recovery->withNewRename($role);
            }
        }
        if (! $recovery->generationRecorded) {
            $recovery = $recovery->withGenerationRecorded();
        }
        if (! $recovery->manifestPromoted) {
            $recovery = $recovery->withManifestPromoted();
        }
        $this->replacements->advance($recovery);
        $this->recordPromotionLineage($installation);
        $this->assertReplacementInstances($installation, $installation->canonicalInstances);
        foreach (TopologyProfile::ROLES as $role) {
            $name = $installation->oldInstances[$role];
            $instance = $this->host->instances([$name])[$name] ?? null;
            if ($instance !== null) {
                $this->assertOldInstance($installation, $role, $instance, $name);
            }
            if (! in_array($role, $recovery->deletedOldRoles, true)) {
                $recovery = $recovery->withOldDeleted($role);
                $this->replacements->advance($recovery);
            }
            if ($instance !== null) {
                $this->host->deleteInstances([$name]);
            }
        }
        foreach ($this->manifests->recorded() as $generation) {
            if ($generation->id === $installation->oldGeneration->id) {
                $this->manifests->forget($generation);
            }
        }
        if (! $recovery->temporaryResourcesCleaned) {
            $cleanup = $this->constructor->cleanup(
                $this->temporaryTarget($installation),
                $installation->resourceOperation,
            );
            if (! $cleanup->successful()) {
                throw new RuntimeException('Clean replacement cleanup failed: '.implode('; ', $cleanup->refused));
            }
            $recovery = $recovery->withTemporaryResourcesCleaned();
        }
        if (! $recovery->stagedResourcesCleaned) {
            $recovery = $recovery->withStagedResourcesCleaned();
        }
        $this->replacements->advance($recovery);

        return $recovery;
    }

    private function rollback(
        TopologySnapshotReplacementRecovery $recovery,
    ): TopologySnapshotReplacementRecovery {
        if ($recovery->phase !== 'rollback_pending') {
            $recovery = $this->failurePhase(
                $recovery,
                'rollback_pending',
                'The interrupted replacement must restore the prior generation.',
                $recovery->nextAction,
            );
        }
        $installation = $recovery->installation;
        if (! $this->generationLock->acquire('standby-generation', $this->operation, timeoutSeconds: 3600)) {
            throw new RuntimeException('Unable to acquire the topology snapshot generation lock for rollback.');
        }
        try {
            foreach (array_reverse(TopologyProfile::ROLES) as $role) {
                $canonical = $installation->canonicalInstances[$role];
                $next = $installation->nextInstances[$role];
                $old = $installation->oldInstances[$role];
                $observed = $this->host->instances([$canonical, $next, $old]);
                if (isset($observed[$old])) {
                    if (isset($observed[$canonical])) {
                        $this->assertReplacementInstance($installation, $role, $observed[$canonical], $canonical);
                        if (isset($observed[$next])) {
                            throw new RuntimeException("Both staged replacement identities exist for {$role} during rollback.");
                        }
                        $this->host->renameInstance($canonical, $next);
                    }
                    $this->host->renameInstance($old, $canonical);
                }
            }
            $this->assertOldGeneration($installation);

            return $this->phase($recovery, 'rolled_back', 'The interrupted replacement was rolled back.');
        } finally {
            $this->generationLock->release();
        }
    }

    private function cleanupPrepared(
        TopologySnapshotReplacementRecovery $recovery,
    ): TopologySnapshotReplacementRecovery {
        $installation = $recovery->installation;
        $staged = $this->host->instances(array_values($installation->nextInstances));
        foreach ($staged as $roleName => $instance) {
            $role = array_search($roleName, $installation->nextInstances, true);
            if (! is_string($role)) {
                throw new RuntimeException('A staged replacement identity is not journaled.');
            }
            $this->assertReplacementInstance($installation, $role, $instance, $roleName);
        }
        if ($staged !== []) {
            $this->host->deleteInstances(array_keys($staged));
        }
        $recovery = $recovery->withStagedResourcesCleaned();
        $promoted = $this->manifests->promoted();
        if ($promoted?->toArray() !== $installation->oldGeneration->toArray()) {
            throw new RuntimeException('Prepared replacement cleanup requires the prior generation to remain promoted.');
        }
        foreach ($this->manifests->recorded() as $generation) {
            if ($generation->id === $installation->newGeneration->id) {
                if ($generation->toArray() !== $installation->newGeneration->toArray()) {
                    throw new RuntimeException('The unpromoted replacement generation record has a different identity.');
                }
                $this->manifests->forget($generation);
            }
        }
        $cleanup = $this->constructor->cleanup($this->temporaryTarget($installation), $installation->resourceOperation);
        if (! $cleanup->successful()) {
            throw new RuntimeException('Clean replacement cleanup failed: '.implode('; ', $cleanup->refused));
        }
        $recovery = $recovery->withTemporaryResourcesCleaned();
        $this->replacements->advance($recovery);

        return $recovery;
    }

    private function recordCommittedManifest(
        TopologySnapshotReplacementRecovery $recovery,
    ): TopologySnapshotReplacementRecovery {
        if ($recovery->phase === 'swap_pending') {
            $recovery = $this->phase($recovery, 'swap_in_progress');
        }
        if ($recovery->phase === 'swap_in_progress') {
            $recovery = $this->phase($recovery, 'manifest_pending');
        }
        if (! $recovery->generationRecorded) {
            $recovery = $recovery->withGenerationRecorded();
            $this->replacements->advance($recovery);
        }
        if (! $recovery->manifestPromoted) {
            $recovery = $recovery->withManifestPromoted();
            $this->replacements->advance($recovery);
        }
        $this->recordPromotionLineage($recovery->installation);

        return $recovery->phase === 'manifest_promoted'
            ? $recovery
            : $this->phase($recovery, 'manifest_promoted');
    }

    private function recordPromotionLineage(TopologySnapshotReplacementInstallation $installation): void
    {
        $existing = $this->promotions->find($installation->newGeneration->id);
        $recordedAt = $existing['recorded_at'] ?? gmdate('Y-m-d\TH:i:s\Z');
        if (! is_string($recordedAt)) {
            throw new RuntimeException('The existing clean reconstruction promotion time is invalid.');
        }
        $this->promotions->record(new ProofPromotionRecord(
            $installation->issue,
            $installation->newGeneration->id,
            $installation->candidateSha,
            $installation->candidateSha,
            $installation->mainSha,
            $installation->newGeneration->preparedFingerprint,
            $installation->manifestFingerprint,
            null,
            $recordedAt,
            'clean-reconstruction',
        ));
    }

    private function installation(
        TopologyRequest $request,
        CapturedProof $capture,
        string $candidateSha,
        string $artifactSha,
        string $mergeSha,
        string $mainSha,
    ): TopologySnapshotReplacementInstallation {
        if ($this->primary->commit() !== $mainSha || $this->primary->dirtyOverlay() !== null) {
            throw new RuntimeException('The clean primary checkout does not match replacement main.');
        }
        $manifest = ProofInputManifest::fromArray($capture->manifest);
        $construction = $manifest->construction;
        if (
            ! $construction->snapshotReplacement
            || $construction->extension !== null
            || $construction->sourceGeneration !== 'generic-base'
            || $construction->target->recipe->nodeKeys() !== TopologyProfile::ROLES
            || $construction->imageAlias === null
            || $construction->imageFingerprint === null
        ) {
            throw new RuntimeException('Captured proof does not authorize a clean registered snapshot replacement.');
        }
        $old = $this->manifests->promoted() ?? throw new RuntimeException(
            'There is no promoted topology snapshot generation to replace.',
        );
        if ($old->manifestSchema !== TopologySnapshotGeneration::SCHEMA) {
            throw new RuntimeException(
                'The promoted topology snapshot generation is legacy; refresh it before replacement.',
            );
        }
        $structural = $this->fingerprints->forCommit($mainSha);
        $desired = $this->fingerprints->withLaravel($structural, $capture->topology->generation->laravel);
        $shape = $desired->manifest;
        if (($shape['base_image_alias'] ?? null) !== $construction->imageAlias) {
            throw new RuntimeException('Accepted merged source changes the recorded generic base alias.');
        }
        $id = substr($mainSha, 0, 12).'-'.substr($desired->value, 0, 12);
        if (! is_int($shape['schema'] ?? null) || ! is_string($shape['cold_epoch'] ?? null)) {
            throw new RuntimeException('The accepted prepared-state manifest is incomplete.');
        }
        $new = new TopologySnapshotGeneration(
            $id,
            $mainSha,
            array_fill_keys(TopologyProfile::ROLES, 'main-'.$id),
            $desired->value,
            $construction->imageFingerprint,
            $capture->topology->generation->laravel,
            $structural->value,
            $shape['schema'],
            $shape['cold_epoch'],
            $construction->imageAlias,
            TopologyProfile::NAME,
            TopologyProfile::ROLES,
            TopologyProfile::CHECKOUT_ROLES,
            $old->id,
            TopologyProfile::ASSIGNMENTS,
        );
        $attempt = $this->attempts === null ? AttemptId::generate() : ($this->attempts)();
        $temporary = TopologyTarget::disposableCold(
            $request->issue,
            $attempt,
            TopologyRecipe::registered($construction->imageAlias),
        );
        $canonicalTarget = TopologyTarget::topologySnapshot($this->identity);
        $temporaryInstances = [];
        $canonicalInstances = [];
        $nextInstances = [];
        $oldInstances = [];
        foreach (TopologyProfile::ROLES as $role) {
            $temporaryInstances[$role] = $temporary->instance($role);
            $canonicalInstances[$role] = $canonicalTarget->instance($role);
            $nextInstances[$role] = $canonicalInstances[$role].'-next';
            $oldInstances[$role] = $canonicalInstances[$role].'-old';
        }

        return new TopologySnapshotReplacementInstallation(
            $request->issue,
            $capture->attempt,
            $attempt,
            $this->operation,
            $candidateSha,
            $artifactSha,
            $mergeSha,
            $mainSha,
            $capture->fingerprint(),
            $manifest->fingerprint(),
            $old,
            $new,
            $construction->imageAlias,
            $construction->imageFingerprint,
            $temporary->network(),
            $temporaryInstances,
            $canonicalInstances,
            $nextInstances,
            $oldInstances,
        );
    }

    private function assertCapture(TopologyRequest $request, CapturedProof $capture): void
    {
        if ($capture->issue !== $request->issue) {
            throw new RuntimeException('The captured proof belongs to another issue.');
        }
        if (ProofInputManifest::fromArray($capture->manifest)->construction->snapshotReplacement !== true) {
            throw new RuntimeException('The captured proof has no snapshot replacement declaration.');
        }
    }

    private function assertRetryIdentity(
        TopologySnapshotReplacementInstallation $installation,
        TopologyRequest $request,
        CapturedProof $capture,
        string $candidateSha,
        string $artifactSha,
        string $mergeSha,
        string $mainSha,
    ): void {
        if (
            $installation->issue !== $request->issue
            || $installation->proofAttempt->value !== $capture->attempt->value
            || $installation->capturedProofFingerprint !== $capture->fingerprint()
            || $installation->candidateSha !== $candidateSha
            || $installation->artifactSha !== $artifactSha
            || $installation->mergeSha !== $mergeSha
            || $installation->mainSha !== $mainSha
        ) {
            throw new RuntimeException('Snapshot replacement retry identities do not match the retained installation.');
        }
    }

    private function assertOldGeneration(TopologySnapshotReplacementInstallation $installation): void
    {
        $snapshots = [];
        foreach (TopologyProfile::ROLES as $role) {
            $name = $installation->canonicalInstances[$role];
            $instance = $this->host->instances([$name])[$name] ?? throw new RuntimeException(
                "The prior topology snapshot {$role} VM is missing.",
            );
            $this->assertOldInstance($installation, $role, $instance, $name);
            $snapshots[$name] = $installation->oldGeneration->snapshots[$role];
        }
        $this->host->assertOwnedSnapshots($snapshots);
    }

    private function assertOldInstance(
        TopologySnapshotReplacementInstallation $installation,
        string $role,
        IncusInstance $instance,
        string $name,
    ): void {
        if (
            ($instance->metadata['user.orbit.e2e.owner'] ?? null) !== 'orbit-e2e'
            || isset($instance->metadata['user.orbit.e2e.issue'])
            || ! $instance->isStopped()
            || $instance->network !== $this->identity->network()
            || $instance->mac !== TopologyTarget::topologySnapshot($this->identity)->mac($role)
            || $instance->disks !== []
        ) {
            throw new RuntimeException("Prior topology snapshot VM {$name} identity does not match.");
        }
        $this->host->assertOwnedSnapshot($name, $installation->oldGeneration->snapshots[$role]);
    }

    /** @param array<string, string> $instances */
    private function assertReplacementInstances(
        TopologySnapshotReplacementInstallation $installation,
        array $instances,
    ): void {
        $observed = $this->host->instances(array_values($instances));
        foreach (TopologyProfile::ROLES as $role) {
            $name = $instances[$role];
            $instance = $observed[$name] ?? throw new RuntimeException("Replacement {$role} VM is missing.");
            $this->assertReplacementInstance($installation, $role, $instance, $name);
        }
    }

    private function assertReplacementInstance(
        TopologySnapshotReplacementInstallation $installation,
        string $role,
        IncusInstance $instance,
        string $name,
    ): void {
        if (
            ($instance->metadata['user.orbit.e2e.owner'] ?? null) !== 'orbit-e2e'
            || ($instance->metadata['user.orbit.e2e.operation'] ?? null) !== $installation->resourceOperation->value
            || ! $instance->isStopped()
            || $instance->network !== $this->identity->network()
            || $instance->mac !== TopologyTarget::topologySnapshot($this->identity)->mac($role)
            || $instance->disks !== []
        ) {
            throw new RuntimeException("Replacement VM {$name} identity does not match its journal.");
        }
        $this->host->assertOwnedSnapshot($name, $installation->newGeneration->snapshots[$role]);
    }

    /** @param list<string> $instances */
    private function assertStopped(array $instances, string $label): void
    {
        $observed = $this->host->instances($instances);
        if (count($observed) !== count($instances) || ! array_all(
            $observed,
            static fn (IncusInstance $instance): bool => $instance->isStopped(),
        )) {
            throw new RuntimeException("The {$label} VMs are not all stopped.");
        }
    }

    private function temporaryTarget(TopologySnapshotReplacementInstallation $installation): TopologyTarget
    {
        return TopologyTarget::disposableCold(
            $installation->issue,
            $installation->replacementAttempt,
            TopologyRecipe::registered($installation->genericImageAlias),
        );
    }

    private function isPromoted(TopologySnapshotGeneration $generation): bool
    {
        return $this->manifests->promoted()?->toArray() === $generation->toArray();
    }

    private function phase(
        TopologySnapshotReplacementRecovery $recovery,
        string $phase,
        ?string $error = null,
        ?string $nextAction = null,
    ): TopologySnapshotReplacementRecovery {
        $updated = $recovery->withPhase($phase, gmdate('Y-m-d\TH:i:s\Z'), $error, $nextAction);
        if (! $updated->terminal()) {
            $this->replacements->advance($updated);
        }

        return $updated;
    }

    private function failurePhase(
        TopologySnapshotReplacementRecovery $recovery,
        string $phase,
        string $error,
        string $nextAction,
    ): TopologySnapshotReplacementRecovery {
        return $this->phase($recovery, $phase, $error, $nextAction);
    }

    private function nextAction(
        TopologyRequest $request,
        string $candidateSha,
        string $artifactSha,
        string $mergeSha,
        string $mainSha,
    ): string {
        return 'bin/e2e-topology closeout '.$request->issue
            .' --candidate='.$candidateSha
            .' --artifact='.$artifactSha
            .' --merge='.$mergeSha
            .' --main-sha='.$mainSha;
    }
}
