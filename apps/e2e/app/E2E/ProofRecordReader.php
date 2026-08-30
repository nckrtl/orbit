<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\AtomicJsonStore;
use App\E2E\Value\AttemptId;
use App\E2E\Value\ProofStatus;
use App\E2E\Value\TopologyTarget;

/**
 * Read the verdict of one attempt's proof record at `evidence/proofs/<issue>/<attempt>.json`.
 *
 * Proof records are written by the proof flow; this reader only answers whether an
 * attempt is proved, so lifecycle commands can refuse to touch a proved topology.
 */
final readonly class ProofRecordReader
{
    public function __construct(
        private AtomicJsonStore $store,
    ) {}

    public function status(string $issue, AttemptId $attempt): ?ProofStatus
    {
        TopologyTarget::assertIssue($issue);
        $record = $this->store->read('evidence/proofs/'.$issue.'/'.$attempt->value.'.json');
        if ($record === null) {
            return null;
        }

        /** @mago-expect analysis:mixed-assignment The record status is validated before use. */
        $status = $record['status'] ?? null;

        return is_string($status) ? ProofStatus::tryFrom($status) : null;
    }

    public function isProved(string $issue, AttemptId $attempt): bool
    {
        return $this->status($issue, $attempt) === ProofStatus::Proved;
    }
}
