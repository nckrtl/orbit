<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/**
 * The exact identity one proof synchronized into its checkout roles: the
 * candidate commit, its Git tree, the guest scripts taken from that commit,
 * and the operation that moved them. Nothing here depends on host worktree state.
 */
final readonly class CandidateSync
{
    public function __construct(
        public string $candidateSha,
        public string $candidateTree,
        public string $guestScriptHash,
        public string $operationId,
    ) {
        foreach ([$candidateSha, $candidateTree] as $object) {
            if (preg_match('/\A[0-9a-f]{40}\z/D', $object) !== 1) {
                throw new InvalidArgumentException('A candidate Git object identity is invalid.');
            }
        }
        if (preg_match('/\A[0-9a-f]{64}\z/D', $guestScriptHash) !== 1) {
            throw new InvalidArgumentException('The candidate guest script hash is invalid.');
        }
        if (preg_match('/\A[0-9a-f]{32}\z/D', $operationId) !== 1) {
            throw new InvalidArgumentException('The candidate sync operation ID is invalid.');
        }
    }

    /** @return array{candidate_sha:string,candidate_tree:string,guest_script_hash:string,operation_id:string} */
    public function toArray(): array
    {
        return [
            'candidate_sha' => $this->candidateSha,
            'candidate_tree' => $this->candidateTree,
            'guest_script_hash' => $this->guestScriptHash,
            'operation_id' => $this->operationId,
        ];
    }

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        if (
            array_keys($value) !== ['candidate_sha', 'candidate_tree', 'guest_script_hash', 'operation_id']
            || ! is_string($value['candidate_sha'])
            || ! is_string($value['candidate_tree'])
            || ! is_string($value['guest_script_hash'])
            || ! is_string($value['operation_id'])
        ) {
            throw new InvalidArgumentException('The candidate sync schema is invalid.');
        }

        return new self(
            $value['candidate_sha'],
            $value['candidate_tree'],
            $value['guest_script_hash'],
            $value['operation_id'],
        );
    }
}
