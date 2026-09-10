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
                $proof = $evidence['proof'];
                $captured = new CapturedProof(
                    $request->issue,
                    $attempt,
                    (string) $proof['candidate_sha'],
                    (string) $proof['plan_sha256'],
                    (string) $proof['manifest_sha256'],
                    $proof,
                    FeatureTopology::fromArray($evidence['topology']),
                    $evidence['manifest'],
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
