<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/** @mago-expect lint:excessive-parameter-list Proof keeps each candidate identity explicit. */
final readonly class ProofResult
{
    public function __construct(
        public string $operationId,
        public string $evidenceId,
        public string $candidateSha,
        public string $candidateTree,
        public string $treeHash,
        public VerificationReport $verification,
    ) {
        if (
            preg_match('/\A[0-9a-f]{32}\z/D', $operationId) !== 1
            || preg_match('/\A[0-9a-f]{32}\z/D', $evidenceId) !== 1
            || preg_match('/\A[0-9a-f]{40}\z/D', $candidateSha) !== 1
            || preg_match('/\A[0-9a-f]{40}\z/D', $candidateTree) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/D', $treeHash) !== 1
            || ! $verification->passed
        ) {
            throw new InvalidArgumentException('The proof result is invalid.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'state' => 'proved',
            'operation_id' => $this->operationId,
            'evidence_id' => $this->evidenceId,
            'candidate_sha' => $this->candidateSha,
            'candidate_tree' => $this->candidateTree,
            'tree_hash' => $this->treeHash,
            'verification' => $this->verification->toArray(),
        ];
    }
}
