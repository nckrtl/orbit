<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\AtomicJsonStore;
use App\E2E\Value\AttemptId;
use App\E2E\Value\ProofResult;
use App\E2E\Value\ProofStatus;
use App\E2E\Value\TopologyTarget;
use RuntimeException;

/**
 * Keep one proof record per exact attempt at `evidence/proofs/<issue>/<attempt>.json`.
 *
 * A record is written once. The only later change is the one-way move from
 * `proved` to `diagnosis`; nothing turns a diagnosis back into a proof, and a
 * released topology never removes its record.
 */
final readonly class ProofStore
{
    public function __construct(
        private AtomicJsonStore $store,
    ) {}

    public function read(string $issue, AttemptId $attempt): ?ProofResult
    {
        TopologyTarget::assertIssue($issue);
        $value = $this->store->read($this->path($issue, $attempt));
        if ($value === null) {
            return null;
        }

        $result = ProofResult::fromArray($value);
        if ($result->issue !== $issue || $result->attempt->value !== $attempt->value) {
            throw new RuntimeException('The proof record identity does not match its path.');
        }

        return $result;
    }

    public function write(ProofResult $result): void
    {
        if ($this->read($result->issue, $result->attempt) !== null) {
            throw new RuntimeException(
                'The proof attempt already has a record; only diagnose() moves proved to diagnosis.',
            );
        }

        $this->store->write($this->path($result->issue, $result->attempt), $result->toArray());
    }

    public function diagnose(string $issue, AttemptId $attempt): ProofResult
    {
        $existing = $this->read($issue, $attempt) ?? throw new RuntimeException(
            'The proof record does not exist.',
        );
        if ($existing->status !== ProofStatus::Proved) {
            throw new RuntimeException('The proof attempt is not proved; a diagnosis cannot be replaced.');
        }

        $diagnosis = $existing->withStatus(ProofStatus::Diagnosis, ProofResult::now());
        $this->replaceProved($existing, $diagnosis);

        return $diagnosis;
    }

    /** The only overwrite: the same evidence with the verdict moved from proved to diagnosis. */
    private function replaceProved(ProofResult $existing, ProofResult $diagnosis): void
    {
        $identity = static fn (ProofResult $result): array => array_diff_key(
            $result->toArray(),
            ['status' => true, 'recorded_at' => true],
        );
        if (
            $existing->status !== ProofStatus::Proved
            || $diagnosis->status !== ProofStatus::Diagnosis
            || $identity($existing) !== $identity($diagnosis)
        ) {
            throw new RuntimeException('The diagnosis does not carry the proved evidence of the attempt.');
        }

        $this->store->write($this->path($diagnosis->issue, $diagnosis->attempt), $diagnosis->toArray());
    }

    private function path(string $issue, AttemptId $attempt): string
    {
        return 'evidence/proofs/'.$issue.'/'.$attempt->value.'.json';
    }
}
