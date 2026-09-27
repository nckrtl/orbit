<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\AtomicJsonStore;
use App\E2E\State\OperationLock;
use App\E2E\State\StatePaths;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\CapturedProof;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\OperationId;
use App\E2E\Value\ProofPlan;
use App\E2E\Value\TopologyRequest;
use RuntimeException;

/** Capture successful proof evidence without changing or releasing its topology. */
final readonly class ProofCaptureService
{
    public function __construct(
        private StatePaths $hostPaths,
        private OperationId $operation,
    ) {}

    public function capture(TopologyRequest $request, ProofPlan $plan): CapturedProof
    {
        DeliveryFlow::requireProof($request->worktree);
        $state = IssueState::forWorktree($request->issue, $request->worktree);
        $lock = new OperationLock($this->hostPaths);
        if (! $lock->acquire('topology-'.$request->issue, $this->operation)) {
            throw new RuntimeException('The issue topology is locked by another harness command.');
        }

        try {
            if ($state->leaseSnapshotReplacement(AttemptPurpose::Proof) !== $plan->snapshotReplacement) {
                throw new RuntimeException(
                    'Proof capture requires the pre-construction snapshot replacement declaration.',
                );
            }
            $evidence = ProofEvidence::capture($state, $plan);
            $attempt = $state->attemptId(AttemptPurpose::Proof);
            $path = 'proof-evidence/'.$request->issue.'/'.$attempt->value.'.json';
            $archive = new AtomicJsonStore($this->hostPaths);
            $archived = $archive->read($path);
            $hostCapture = $archived === null ? null : CapturedProof::fromStoredArray($archived);
            $localCapture = $state->capturedProof($attempt);
            if (
                $hostCapture !== null
                && $localCapture !== null
                && $hostCapture->toArray() !== $localCapture->toArray()
            ) {
                throw new RuntimeException('The retained proof archive and worktree capture differ.');
            }

            $captured = $localCapture ?? $hostCapture;
            if ($captured === null) {
                $proof = $evidence['proof'] ?? null;
                $topology = $evidence['topology'] ?? null;
                $manifest = $evidence['manifest'] ?? null;
                if (! is_array($proof) || ! is_array($topology) || ! is_array($manifest)) {
                    throw new RuntimeException('Proof capture evidence is invalid.');
                }
                $candidateSha = $proof['candidate_sha'] ?? null;
                $planSha256 = $proof['plan_sha256'] ?? null;
                $manifestSha256 = $proof['manifest_sha256'] ?? null;
                if (! is_string($candidateSha) || ! is_string($planSha256) || ! is_string($manifestSha256)) {
                    throw new RuntimeException('Proof capture evidence is invalid.');
                }
                $captured = new CapturedProof(
                    $request->issue,
                    $attempt,
                    $candidateSha,
                    $planSha256,
                    $manifestSha256,
                    $proof,
                    FeatureTopology::fromArray($topology),
                    $manifest,
                    gmdate('Y-m-d\TH:i:s\Z'),
                );
            } elseif ($captured->evidence() !== $evidence) {
                throw new RuntimeException('Captured proof evidence is immutable and no longer matches live proof state.');
            }

            if ($hostCapture === null) {
                $archive->write($path, $captured->toArray());
            }
            if ($localCapture === null) {
                $state->captureProof($captured);
            }

            return $captured;
        } finally {
            $lock->release();
        }
    }
}
