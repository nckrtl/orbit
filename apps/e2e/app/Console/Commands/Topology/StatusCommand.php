<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\Value\AttemptPurpose;
use Throwable;

/** Read-only: reports discovery and proof from an issue's worktree state. */
final class StatusCommand extends E2ECommand
{
    #[\Override]
    protected $signature = 'topology:status {issue} '.self::WORKTREE_OPTION.' {--json}';
    #[\Override]
    protected $description = 'Report the issue discovery and proof topologies without touching infrastructure';

    public function handle(): int
    {
        try {
            $request = $this->request();
            $state = $this->state($request);
            if (! $state->hasAttempt()) {
                $this->outputJson([
                    'state' => 'absent',
                    'issue' => $request->issue,
                    'worktree' => $request->worktree,
                    'proof' => $state->proof(),
                ], 'absent');

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
                ],
                $attempt['purpose'].' '.$attempt['attempt_id'].($state->isProved() ? ' proved' : ''),
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->outputFailure($exception);

            return self::FAILURE;
        }
    }
}
