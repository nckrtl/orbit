<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\IssueState;
use App\E2E\TopologySnapshotReplacementStore;
use App\E2E\Value\AttemptPurpose;
use Throwable;

/** Read-only: reports discovery and proof from an issue's worktree state. */
final class StatusCommand extends E2ECommand
{
    #[\Override]
    protected $signature = 'topology:status {issue} '.self::WORKTREE_OPTION.' {--json}';

    #[\Override]
    protected $description = 'Report the issue discovery and proof topologies without touching infrastructure';

    public function handle(TopologySnapshotReplacementStore $replacements): int
    {
        try {
            $request = $this->request();
            $state = $this->state($request);
            if (! $state->hasAttempt()) {
                $captured = $state->capturedProof();
                $this->outputJson(
                    [
                        'state' => $captured !== null ? 'captured' : 'absent',
                        'issue' => $request->issue,
                        'worktree' => $request->worktree,
                        'proof' => $state->proof(),
                        'captured_topology' => $captured?->topology->toArray(),
                        ...($captured === null ? [] : $this->proofLifecycle($state, $replacements)),
                    ],
                    $captured !== null ? 'captured '.$captured->attempt->value : 'absent',
                );

                return self::SUCCESS;
            }
            $hasDiscovery = $state->hasAttempt(AttemptPurpose::Discovery);
            $hasProof = $state->hasAttempt(AttemptPurpose::Proof);
            $hasCandidate = $state->hasAttempt(AttemptPurpose::CandidateConvergence);
            if ($hasCandidate) {
                $purposes = array_values(array_filter([
                    $hasDiscovery ? 'discovery' : null,
                    $hasProof ? 'proof' : null,
                    'candidate-convergence',
                ]));
                $candidate = $state->attempt(AttemptPurpose::CandidateConvergence);
                $proofLifecycle = $this->proofLifecycle($state, $replacements);
                $this->outputJson(
                    [
                        'state' => implode('+', $purposes),
                        'issue' => $request->issue,
                        'worktree' => $request->worktree,
                        'discovery_topology' => $state->topology(AttemptPurpose::Discovery)?->toArray(),
                        'proof_topology' => $state->topology(AttemptPurpose::Proof)?->toArray(),
                        'candidate_attempt_id' => $candidate['attempt_id'],
                        'candidate_topology' => $state->topology(AttemptPurpose::CandidateConvergence)?->toArray(),
                        'proof' => $state->proof(),
                        'candidate_convergence' => $state->candidateConvergence(),
                        ...(
                            $hasProof || $proofLifecycle['capture'] !== null
                                ? $proofLifecycle
                                : []
                        ),
                    ],
                    implode('+', $purposes).' '.$candidate['attempt_id'],
                );

                return self::SUCCESS;
            }
            if ($hasDiscovery && $hasProof) {
                $discovery = $state->attempt(AttemptPurpose::Discovery);
                $proofAttempt = $state->attempt(AttemptPurpose::Proof);
                $proof = $state->proof();
                $proofStatus = ($proof['attempt_id'] ?? null) === $proofAttempt['attempt_id']
                    ? (string) ($proof['status'] ?? 'pending')
                    : 'pending';
                $this->outputJson([
                    'state' => 'discovery+proof',
                    'issue' => $request->issue,
                    'attempt_id' => $discovery['attempt_id'],
                    'proof_attempt_id' => $proofAttempt['attempt_id'],
                    'worktree' => $request->worktree,
                    'acquired_at' => $discovery['acquired_at'],
                    'proof_acquired_at' => $proofAttempt['acquired_at'],
                    'proved' => $state->isProved(),
                    'topology' => $state->topology(AttemptPurpose::Discovery)?->toArray(),
                    'proof_topology' => $state->topology(AttemptPurpose::Proof)?->toArray(),
                    'proof' => $proof,
                    ...$this->proofLifecycle($state, $replacements),
                ], "discovery {$discovery['attempt_id']}; proof {$proofAttempt['attempt_id']} {$proofStatus}");

                return self::SUCCESS;
            }
            $purpose = $hasDiscovery ? AttemptPurpose::Discovery : AttemptPurpose::Proof;
            $attempt = $state->attempt($purpose);
            $topology = $state->topology($purpose);
            $this->outputJson(
                [
                    'state' => $attempt['purpose'],
                    'issue' => $request->issue,
                    'attempt_id' => $attempt['attempt_id'],
                    'worktree' => $request->worktree,
                    'acquired_at' => $attempt['acquired_at'],
                    'proved' => $state->isProved(),
                    'topology' => $topology?->toArray(),
                    'proof' => $state->proof(),
                    ...(
                        $purpose === AttemptPurpose::Proof
                            ? $this->proofLifecycle($state, $replacements)
                            : []
                    ),
                ],
                $attempt['purpose'].' '.$attempt['attempt_id'].($state->isProved() ? ' proved' : ''),
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->outputFailure($exception);

            return self::FAILURE;
        }
    }

    /** @return array<string, mixed> */
    private function proofLifecycle(
        IssueState $state,
        TopologySnapshotReplacementStore $replacements,
    ): array {
        $attempt = $state->hasAttempt(AttemptPurpose::Proof)
            ? $state->attemptId(AttemptPurpose::Proof)
            : null;
        $captured = $state->capturedProof($attempt);
        $review = $captured === null ? null : $state->reviewRecord($captured->attempt);
        $evaluation = $captured === null ? null : $state->reviewEvaluation($captured->attempt);
        $closeout = $captured === null ? null : $state->closeoutRecord($captured->attempt);
        $retained = $captured !== null
            && $state->hasAttempt(AttemptPurpose::Proof)
            && $state->attemptId(AttemptPurpose::Proof)->value === $captured->attempt->value
                ? $state->topology(AttemptPurpose::Proof)
                : null;
        $activeReplacement = $replacements->active();
        if ($activeReplacement?->installation->issue !== $state->issue) {
            $activeReplacement = null;
        }
        $archivedReplacement = $captured === null
            ? null
            : $replacements->archived($captured->attempt);

        return [
            'capture' => $captured === null ? null : [
                'issue' => $captured->issue,
                'attempt_id' => $captured->attempt->value,
                'candidate_sha' => $captured->candidateSha,
                'plan_sha256' => $captured->planSha256,
                'manifest_sha256' => $captured->manifestSha256,
                'captured_at' => $captured->capturedAt,
                'fingerprint' => $captured->fingerprint(),
            ],
            'retained_topology' => $retained?->toArray(),
            'review_record' => $review?->toArray(),
            'review_evaluation' => $evaluation?->toArray(),
            'closeout' => $closeout?->toArray(),
            'snapshot_replacement' => $activeReplacement?->toArray() ?? $archivedReplacement?->toArray(),
        ];
    }
}
