<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Git\GitRepository;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\OperationLock;
use App\E2E\State\SecretRedactor;
use App\E2E\State\StatePaths;
use App\E2E\Value\AttemptId;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\CapturedProof;
use App\E2E\Value\OperationId;
use App\E2E\Value\ProofCloseoutRecord;
use App\E2E\Value\ProofEquivalenceReport;
use App\E2E\Value\ProofEquivalenceResult;
use App\E2E\Value\ProofReviewEvaluation;
use App\E2E\Value\RefreshResult;
use App\E2E\Value\TopologyRequest;
use Closure;
use RuntimeException;

/** Refresh merged main before releasing the exact retained successful proof. */
final readonly class ProofCloseoutService
{
    /**
     * @param  Closure(string): RefreshResult  $refresh
     * @param  Closure(TopologyRequest, CapturedProof): array<string, mixed>  $release
     */
    public function __construct(
        private GitRepository $primary,
        private StatePaths $hostPaths,
        private OperationId $operation,
        private SecretRedactor $redactor,
        private Closure $refresh,
        private Closure $release,
    ) {}

    public function closeout(
        TopologyRequest $request,
        string $candidateSha,
        string $artifactSha,
        string $mergeSha,
        string $mainSha,
    ): ProofCloseoutRecord {
        DeliveryFlow::requireProof($request->worktree);
        $state = IssueState::forWorktree($request->issue, $request->worktree);
        $lock = new OperationLock($this->hostPaths);
        if (! $lock->acquire('topology-'.$request->issue, $this->operation, timeoutSeconds: 3600)) {
            throw new RuntimeException('The issue topology is locked by another harness command.');
        }

        try {
            $feature = new GitRepository($request->worktree);
            $this->assertGitBinding($request, $feature, $candidateSha, $artifactSha, $mergeSha, $mainSha);
            $capture = $this->capture($request, $state);
            $existing = $this->closeoutRecord($state, $capture);
            $this->assertAcceptedCandidate($state, $capture->candidateSha, $candidateSha);
            $this->assertReviewReady($state, $capture->attempt);
            $active = $state->hasAttempt(AttemptPurpose::Proof);
            $this->assertRetryIdentity($existing, $candidateSha, $artifactSha, $mergeSha, $mainSha, $active);
            if ($existing?->state === 'complete') {
                if ($active) {
                    throw new RuntimeException('A complete proof closeout still has an active proof topology.');
                }

                return $existing;
            }

            if ($active) {
                if (
                    $state->attemptId(AttemptPurpose::Proof)->value !== $capture->attempt->value
                    || $state->requireTopology(AttemptPurpose::Proof)->toArray() !== $capture->topology->toArray()
                ) {
                    throw new RuntimeException('The retained proof topology does not match its immutable capture.');
                }
            } elseif ($existing?->state !== 'refresh-succeeded') {
                throw new RuntimeException('Closeout requires the exact retained proof topology before refresh.');
            }

            $generationId = $existing?->generationId;
            if ($existing?->state !== 'refresh-succeeded') {
                $refresh = ($this->refresh)($mainSha);
                if (! $refresh->successful()) {
                    $failed = new ProofCloseoutRecord(
                        'refresh-failed',
                        $request->issue,
                        $capture->attempt,
                        $candidateSha,
                        $artifactSha,
                        $mergeSha,
                        $mainSha,
                        $refresh->generationId,
                        $this->redactor->redact($refresh->error ?? 'Topology snapshot refresh failed.'),
                        gmdate('Y-m-d\TH:i:s\Z'),
                    );
                    $this->writeRecord($state, $failed);

                    return $failed;
                }
                $generationId = $refresh->generationId ?? throw new RuntimeException(
                    'A successful topology snapshot refresh has no generation identity.',
                );
                $refreshed = new ProofCloseoutRecord(
                    'refresh-succeeded',
                    $request->issue,
                    $capture->attempt,
                    $candidateSha,
                    $artifactSha,
                    $mergeSha,
                    $mainSha,
                    $generationId,
                    null,
                    gmdate('Y-m-d\TH:i:s\Z'),
                );
                $this->writeRecord($state, $refreshed);
            }

            ($this->release)($request, $capture);
            $completedMainSha = $existing?->state === 'refresh-succeeded'
                ? $existing->mainSha
                : $mainSha;
            $complete = new ProofCloseoutRecord(
                'complete',
                $request->issue,
                $capture->attempt,
                $candidateSha,
                $artifactSha,
                $mergeSha,
                $completedMainSha,
                $generationId,
                null,
                gmdate('Y-m-d\TH:i:s\Z'),
            );
            $this->writeRecord($state, $complete);

            return $complete;
        } finally {
            $lock->release();
        }
    }

    private function assertGitBinding(
        TopologyRequest $request,
        GitRepository $feature,
        string $candidateSha,
        string $artifactSha,
        string $mergeSha,
        string $mainSha,
    ): void {
        if ($feature->commonDirectory() !== $this->primary->commonDirectory()) {
            throw new RuntimeException('The feature and primary repositories do not match.');
        }
        if ($feature->dirtyOverlay() !== null || $feature->commit() !== $candidateSha) {
            throw new RuntimeException('The issue worktree does not match the clean accepted candidate.');
        }
        if ($feature->loopCommit($request->issue, $candidateSha) !== $artifactSha) {
            throw new RuntimeException('The loop artifact does not match the accepted candidate binding.');
        }
        if (
            $this->primary->dirtyOverlay() !== null
            || $this->primary->commit() !== $mainSha
            || ! $this->primary->isAncestor($mergeSha, $mainSha)
        ) {
            throw new RuntimeException('Current clean main does not contain the verified merge.');
        }
        $parents = $this->primary->parents($mergeSha);
        if (count($parents) !== 2 || $parents[1] !== $candidateSha) {
            throw new RuntimeException('The verified merge second parent is not the exact accepted candidate.');
        }
    }

    private function assertAcceptedCandidate(IssueState $state, string $provedSha, string $candidateSha): void
    {
        if ($provedSha === $candidateSha) {
            return;
        }
        $raw = $state->equivalence();
        $report = is_array($raw) ? ProofEquivalenceReport::fromArray($raw) : null;
        if (
            $report === null
            || $report->provedSha !== $provedSha
            || $report->acceptedSha !== $candidateSha
            || ! in_array($report->result, [ProofEquivalenceResult::Exact, ProofEquivalenceResult::Equivalent], true)
        ) {
            throw new RuntimeException('The accepted candidate has no reusable retained-proof decision.');
        }
    }

    private function assertReviewReady(IssueState $state, AttemptId $attempt): void
    {
        $record = $state->reviewRecord($attempt);
        if ($record === null || ! $record->hasActions()) {
            throw new RuntimeException('Closeout requires recorded interactive proof review actions.');
        }
        $evaluation = $state->reviewEvaluation($attempt);
        if ($evaluation === null || $evaluation->status !== 'ready') {
            throw new RuntimeException('Closeout requires a ready proof review evaluation.');
        }
        $expected = ProofReviewEvaluation::forRecord($record, $evaluation->evaluatedAt);
        if ($expected->toArray() !== $evaluation->toArray()) {
            throw new RuntimeException('The proof review evaluation is stale or does not match its action record.');
        }
        $archive = new AtomicJsonStore($this->hostPaths);
        $reviewPath = 'proof-review/'.$record->issue.'/'.$record->attempt->value.'.json';
        $evaluationPath = 'proof-review-evaluation/'.$record->issue.'/'.$record->attempt->value.'.json';
        if (
            $archive->read($reviewPath) !== $record->toArray()
            || $archive->read($evaluationPath) !== $evaluation->toArray()
        ) {
            throw new RuntimeException('The retained proof review archive and worktree record differ.');
        }
    }

    private function assertRetryIdentity(
        ?ProofCloseoutRecord $record,
        string $candidateSha,
        string $artifactSha,
        string $mergeSha,
        string $mainSha,
        bool $active,
    ): void {
        $mainMayAdvance = $record !== null && (
            $record->state === 'refresh-failed'
            || ! $active && in_array($record->state, ['refresh-succeeded', 'complete'], true)
        );
        if (
            $record !== null
            && (
                $record->candidateSha !== $candidateSha
                || $record->artifactSha !== $artifactSha
                || $record->mergeSha !== $mergeSha
                || ! $mainMayAdvance && $record->mainSha !== $mainSha
            )
        ) {
            throw new RuntimeException('Closeout retry identities do not match the retained attempt.');
        }
    }

    private function capture(TopologyRequest $request, IssueState $state): CapturedProof
    {
        $proof = $state->proof() ?? [];
        $attemptValue = $proof['attempt_id'] ?? null;
        if (! is_string($attemptValue)) {
            throw new RuntimeException('Closeout requires immutable captured proof evidence.');
        }
        $attempt = new AttemptId($attemptValue);
        $local = $state->capturedProof($attempt);
        $raw = new AtomicJsonStore($this->hostPaths)->read(
            'proof-evidence/'.$request->issue.'/'.$attempt->value.'.json',
        );
        if (! is_array($raw)) {
            throw new RuntimeException('The retained proof archive is missing.');
        }
        $archived = CapturedProof::fromStoredArray($raw);
        if ($local === null) {
            $state->captureProof($archived);

            return $archived;
        }
        if ($archived->toArray() !== $local->toArray()) {
            throw new RuntimeException('The retained proof archive and worktree capture differ.');
        }

        return $local;
    }

    private function closeoutRecord(IssueState $state, CapturedProof $capture): ?ProofCloseoutRecord
    {
        $local = $state->closeoutRecord($capture->attempt);
        $raw = new AtomicJsonStore($this->hostPaths)->read(
            'proof-closeout/'.$capture->issue.'/'.$capture->attempt->value.'.json',
        );
        $archived = is_array($raw) ? ProofCloseoutRecord::fromArray($raw) : null;
        if ($local === null && $archived === null) {
            return null;
        }
        if ($archived === null) {
            throw new RuntimeException('The retained proof closeout archive is missing.');
        }
        if ($local === null || $archived->canReplace($local)) {
            $state->writeCloseoutRecord($archived);

            return $archived;
        }
        if ($local->toArray() !== $archived->toArray()) {
            throw new RuntimeException('The retained proof closeout archive and worktree record differ.');
        }

        return $local;
    }

    private function writeRecord(IssueState $state, ProofCloseoutRecord $record): void
    {
        $archive = new AtomicJsonStore($this->hostPaths);
        $path = 'proof-closeout/'.$record->issue.'/'.$record->attempt->value.'.json';
        $existing = $archive->read($path);
        if ($existing !== null && ! $record->canReplace(ProofCloseoutRecord::fromArray($existing))) {
            throw new RuntimeException('The archived proof closeout record cannot be replaced.');
        }
        $archive->write($path, $record->toArray());
        $state->writeCloseoutRecord($record);
    }
}
