<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\Value\AttemptId;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\CandidateConvergenceResult;
use App\E2E\Value\CapturedProof;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\OperationId;
use App\E2E\Value\ProofCloseoutRecord;
use App\E2E\Value\ProofReviewEvaluation;
use App\E2E\Value\ProofReviewRecord;
use App\E2E\Value\TopologyExtension;
use RuntimeException;

/**
 * The state of one issue's discovery and proof topologies, kept under `<worktree>/.e2e/`.
 *
 * `attempt.json` and `topology.json` hold discovery. `proof-attempt.json` and
 * `proof-topology.json` hold the fresh proof while discovery remains available.
 * `proof.json` is the last proof result and `log` is a plain-text line per
 * harness command. Legacy single proof leases remain readable and are migrated
 * when discovery is acquired.
 */
final readonly class IssueState
{
    public const string ATTEMPT = 'attempt.json';

    public const string TOPOLOGY = 'topology.json';

    public const string PROOF_ATTEMPT = 'proof-attempt.json';

    public const string PROOF_TOPOLOGY = 'proof-topology.json';

    public const string CANDIDATE_ATTEMPT = 'candidate-attempt.json';

    public const string CANDIDATE_TOPOLOGY = 'candidate-topology.json';

    public const string CANDIDATE_CONVERGENCE = 'candidate-convergence.json';

    public const string PROOF = 'proof.json';

    public const string EQUIVALENCE = 'equivalence.json';

    private AtomicJsonStore $store;

    public function __construct(
        public string $issue,
        public string $worktree,
        private StatePaths $paths,
    ) {
        $this->store = new AtomicJsonStore($paths);
    }

    public static function forWorktree(string $issue, string $worktree): self
    {
        return new self($issue, $worktree, StatePaths::forWorktree($worktree));
    }

    /** Whether the worktree names the selected topology, or either topology when omitted. */
    public function hasAttempt(?AttemptPurpose $purpose = null): bool
    {
        if ($purpose !== null) {
            return $this->rawAttempt($purpose) !== null;
        }

        return
            $this->hasAttempt(AttemptPurpose::Discovery)
            || $this->hasAttempt(AttemptPurpose::Proof)
            || $this->hasAttempt(AttemptPurpose::CandidateConvergence);
    }

    /**
     * @return array{issue:string,attempt_id:string,purpose:string,operation_id:string,acquired_at:string,extension?:null|string}
     */
    public function attempt(?AttemptPurpose $purpose = null): array
    {
        $purpose ??= $this->onlyAttemptPurpose();
        $lease = $this->rawAttempt($purpose);
        if ($lease === null) {
            throw new RuntimeException(
                $this->hasAttempt()
                    ? "{$this->issue} has no active {$purpose->value} attempt."
                    : "{$this->issue} has no active attempt.",
            );
        }
        if (
            ($lease['issue'] ?? null) !== $this->issue
            || ! is_string($lease['attempt_id'] ?? null)
            || preg_match('/\A[0-9a-f]{32}\z/D', $lease['attempt_id']) !== 1
            || ($lease['purpose'] ?? null) !== $purpose->value
            || ! is_string($lease['operation_id'] ?? null)
            || preg_match('/\A[0-9a-f]{32}\z/D', $lease['operation_id']) !== 1
            || ! is_string($lease['acquired_at'] ?? null)
            || array_key_exists('extension', $lease)
            && $lease['extension'] !== null
            && $lease['extension'] !== TopologyExtension::AppProd->value
        ) {
            throw new RuntimeException("The {$this->issue} attempt lease is invalid.");
        }

        /** @var array{issue:string,attempt_id:string,purpose:string,operation_id:string,acquired_at:string,extension?:null|string} $lease */
        return $lease;
    }

    public function attemptId(?AttemptPurpose $purpose = null): AttemptId
    {
        return new AttemptId($this->attempt($purpose)['attempt_id']);
    }

    public function operationId(?AttemptPurpose $purpose = null): OperationId
    {
        return new OperationId($this->attempt($purpose)['operation_id']);
    }

    public function writeAttempt(
        AttemptId $attempt,
        AttemptPurpose $purpose,
        OperationId $operation,
        ?TopologyExtension $extension = null,
    ): void {
        if ($purpose === AttemptPurpose::Discovery) {
            $this->migrateLegacyProof();
        }
        $this->store->write($this->attemptPath($purpose), [
            'issue' => $this->issue,
            'attempt_id' => $attempt->value,
            'purpose' => $purpose->value,
            'operation_id' => $operation->value,
            'acquired_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'extension' => $extension?->value,
        ]);
    }

    public function leaseHasExtension(AttemptPurpose $purpose): bool
    {
        return array_key_exists('extension', $this->attempt($purpose));
    }

    public function leaseExtension(AttemptPurpose $purpose): ?TopologyExtension
    {
        $lease = $this->attempt($purpose);
        if (! array_key_exists('extension', $lease)) {
            throw new RuntimeException("The {$this->issue} {$purpose->value} lease extension target is ambiguous.");
        }

        return $lease['extension'] === null ? null : TopologyExtension::AppProd;
    }

    /** Add only the missing target evidence after the caller validates external recovery constraints. */
    public function recoverLeaseExtension(
        AttemptPurpose $purpose,
        AttemptId $expectedAttempt,
        ?TopologyExtension $extension,
    ): void {
        $lease = $this->attempt($purpose);
        if ($lease['attempt_id'] !== $expectedAttempt->value) {
            throw new RuntimeException(
                "The expected {$purpose->value} attempt {$expectedAttempt->value} does not match the active lease.",
            );
        }
        if (array_key_exists('extension', $lease)) {
            $current = $lease['extension'];
            if ($current !== $extension?->value) {
                throw new RuntimeException('Recovery cannot replace the lease extension target.');
            }

            return;
        }

        $lease['extension'] = $extension?->value;
        $this->store->write($this->attemptPath($purpose), $lease);
    }

    public function topology(?AttemptPurpose $purpose = null): ?FeatureTopology
    {
        if ($purpose === null && ! $this->hasAttempt()) {
            return null;
        }
        $purpose ??= $this->onlyAttemptPurpose();
        $value = $this->store->read($this->topologyPath($purpose));
        if ($value === null) {
            return null;
        }
        $topology = FeatureTopology::fromArray($value);
        if ($topology->target->issue !== $this->issue) {
            throw new RuntimeException('The topology record belongs to another issue.');
        }
        if ($topology->purpose !== $purpose) {
            throw new RuntimeException('The topology record has the wrong attempt purpose.');
        }
        if ($this->hasAttempt($purpose)) {
            $lease = $this->attempt($purpose);
            if ($topology->attempt->value !== $lease['attempt_id']) {
                throw new RuntimeException('The attempt lease and the topology record name different attempts.');
            }
            if (
                array_key_exists('extension', $lease)
                && $topology->construction->extension?->value !== $lease['extension']
            ) {
                throw new RuntimeException('The attempt lease and the topology record name different extensions.');
            }
        }

        return $topology;
    }

    /** The selected topology; its lease and record must name the same attempt. */
    public function requireTopology(?AttemptPurpose $purpose = null): FeatureTopology
    {
        $purpose ??= $this->onlyAttemptPurpose();
        $this->attemptId($purpose);
        $topology = $this->topology($purpose) ?? throw new RuntimeException(
            "{$this->issue} has an active {$purpose->value} lease but no topology record.",
        );

        return $topology;
    }

    public function writeTopology(FeatureTopology $topology): void
    {
        if ($topology->purpose === AttemptPurpose::Discovery) {
            $this->migrateLegacyProof();
        }
        $this->store->write($this->topologyPath($topology->purpose), $topology->toArray());
    }

    /** @return array<array-key, mixed>|null */
    public function proof(): ?array
    {
        return $this->store->read(self::PROOF);
    }

    /** @param array<array-key, mixed> $result */
    public function writeProof(array $result): void
    {
        $this->store->write(self::PROOF, $result);
    }

    /** @return array<array-key, mixed>|null */
    public function candidateConvergence(): ?array
    {
        $value = $this->store->read(self::CANDIDATE_CONVERGENCE);

        return $value === null ? null : CandidateConvergenceResult::fromArray($value)->toArray();
    }

    public function writeCandidateConvergence(CandidateConvergenceResult $result): void
    {
        $this->store->write(self::CANDIDATE_CONVERGENCE, $result->toArray());
    }

    /** @return array<array-key, mixed>|null */
    public function proofInputManifest(string $fingerprint): ?array
    {
        $this->assertFingerprint($fingerprint);

        return $this->store->read('proof-inputs/'.$fingerprint.'.json');
    }

    /** @param array<array-key, mixed> $manifest */
    public function writeProofInputManifest(string $fingerprint, array $manifest): void
    {
        $this->writeImmutable('proof-inputs/'.$fingerprint.'.json', $manifest);
    }

    /** @return array<array-key, mixed>|null */
    public function equivalence(): ?array
    {
        $pointer = $this->store->read(self::EQUIVALENCE);
        if ($pointer === null) {
            return null;
        }
        if (array_keys($pointer) !== ['fingerprint'] || ! is_string($pointer['fingerprint'])) {
            throw new RuntimeException('The equivalence report pointer is invalid.');
        }
        $this->assertFingerprint($pointer['fingerprint']);

        return
            $this->store->read('equivalence/'.$pointer['fingerprint'].'.json') ?? throw new RuntimeException(
                'The equivalence report is missing.',
            );
    }

    /** @param array<array-key, mixed> $report */
    public function writeEquivalence(string $fingerprint, array $report): void
    {
        $this->assertFingerprint($fingerprint);
        $this->writeImmutable('equivalence/'.$fingerprint.'.json', $report);
        $this->store->write(self::EQUIVALENCE, ['fingerprint' => $fingerprint]);
    }

    /** A proved attempt stays alive for review; `exec` and `sync` must not change it. */
    public function isProved(): bool
    {
        $proof = $this->proof();

        return
            $proof !== null
            && ($proof['status'] ?? null) === 'proved'
            && $this->hasAttempt(AttemptPurpose::Proof)
            && ($proof['attempt_id'] ?? null) === $this->attempt(AttemptPurpose::Proof)['attempt_id'];
    }

    /** Captured evidence remains usable after the lease and virtual machines are released. */
    public function proofTopology(): ?FeatureTopology
    {
        if ($this->isProved()) {
            return $this->requireTopology(AttemptPurpose::Proof);
        }
        $proof = $this->proof() ?? [];
        $attempt = $proof['attempt_id'] ?? null;
        if (($proof['status'] ?? null) !== 'proved' || ! is_string($attempt)) {
            return null;
        }
        $attempt = new AttemptId($attempt);
        $captured = $this->store->read('captured-proof/'.$attempt->value.'.json');
        if ($captured === null) {
            return null;
        }
        if (($captured['proof'] ?? null) !== $proof || ! is_array($captured['topology'] ?? null)) {
            throw new RuntimeException('Captured proof evidence does not match the current proof result.');
        }
        $topology = FeatureTopology::fromArray($captured['topology']);
        if ($topology->attempt->value !== $attempt->value || $topology->target->issue !== $this->issue) {
            throw new RuntimeException('Captured proof topology has a different identity.');
        }

        return $topology;
    }

    /** The immutable typed capture, distinct from the mutable retained live topology. */
    public function capturedProof(?AttemptId $attempt = null): ?CapturedProof
    {
        $attempt ??= $this->proofAttemptFromResult();
        if ($attempt === null) {
            return null;
        }
        $captured = $this->store->read($this->attemptEvidencePath('captured-proof', $attempt));
        if ($captured === null) {
            return null;
        }

        $capture = CapturedProof::fromStoredArray($captured);
        if ($capture->issue !== $this->issue || $capture->attempt->value !== $attempt->value) {
            throw new RuntimeException('Captured proof evidence has a different issue or attempt identity.');
        }

        return $capture;
    }

    /**
     * Store one typed capture. The array form retains read/write compatibility
     * with evidence captured by the previous release command.
     *
     * @param  CapturedProof|array<array-key, mixed>  $evidence
     */
    public function captureProof(CapturedProof|array $evidence): void
    {
        if (is_array($evidence)) {
            $attempt = new AttemptId((string) ($evidence['proof']['attempt_id'] ?? ''));
            $this->writeImmutable($this->attemptEvidencePath('captured-proof', $attempt), $evidence);

            return;
        }
        if ($evidence->issue !== $this->issue || $evidence->proof !== $this->proof()) {
            throw new RuntimeException('Captured proof evidence does not match the current issue proof.');
        }
        $this->writeImmutable(
            $this->attemptEvidencePath('captured-proof', $evidence->attempt),
            $evidence->toArray(),
        );
    }

    public function reviewRecord(?AttemptId $attempt = null): ?ProofReviewRecord
    {
        $attempt ??= $this->proofAttemptFromResult();
        if ($attempt === null) {
            return null;
        }
        $value = $this->store->read($this->attemptEvidencePath('proof-review', $attempt));

        return $value === null ? null : ProofReviewRecord::fromArray($value);
    }

    public function writeReviewRecord(ProofReviewRecord $record): void
    {
        if ($record->issue !== $this->issue) {
            throw new RuntimeException('The proof review record belongs to another issue.');
        }
        $capture = $this->capturedProof($record->attempt);
        if ($capture === null || $capture->candidateSha !== $record->candidateSha) {
            throw new RuntimeException('The proof review record does not match captured proof evidence.');
        }
        $path = $this->attemptEvidencePath('proof-review', $record->attempt);
        $existing = $this->store->read($path);
        if ($existing !== null && ! $record->canReplace(ProofReviewRecord::fromArray($existing))) {
            throw new RuntimeException('The proof review record cannot replace its retained history.');
        }
        $this->store->write($path, $record->toArray());
    }

    public function reviewEvaluation(?AttemptId $attempt = null): ?ProofReviewEvaluation
    {
        $attempt ??= $this->proofAttemptFromResult();
        if ($attempt === null) {
            return null;
        }
        $value = $this->store->read($this->attemptEvidencePath('proof-review-evaluation', $attempt));

        return $value === null ? null : ProofReviewEvaluation::fromArray($value);
    }

    public function writeReviewEvaluation(ProofReviewEvaluation $evaluation): void
    {
        if ($evaluation->issue !== $this->issue) {
            throw new RuntimeException('The proof review evaluation belongs to another issue.');
        }
        $record = $this->reviewRecord($evaluation->attempt);
        if ($record === null || $record->candidateSha !== $evaluation->candidateSha) {
            throw new RuntimeException('The proof review evaluation has no matching review record.');
        }
        $expected = ProofReviewEvaluation::forRecord($record, $evaluation->evaluatedAt);
        if ($expected->toArray() !== $evaluation->toArray()) {
            throw new RuntimeException('The proof review evaluation does not match its review record.');
        }
        $this->store->write(
            $this->attemptEvidencePath('proof-review-evaluation', $evaluation->attempt),
            $evaluation->toArray(),
        );
    }

    public function closeoutRecord(?AttemptId $attempt = null): ?ProofCloseoutRecord
    {
        $attempt ??= $this->proofAttemptFromResult();
        if ($attempt === null) {
            return null;
        }
        $value = $this->store->read($this->attemptEvidencePath('proof-closeout', $attempt));

        return $value === null ? null : ProofCloseoutRecord::fromArray($value);
    }

    public function writeCloseoutRecord(ProofCloseoutRecord $record): void
    {
        if ($record->issue !== $this->issue) {
            throw new RuntimeException('The proof closeout record belongs to another issue.');
        }
        $capture = $this->capturedProof($record->attempt);
        if ($capture === null) {
            throw new RuntimeException('The proof closeout record has no captured proof evidence.');
        }
        $path = $this->attemptEvidencePath('proof-closeout', $record->attempt);
        $existing = $this->store->read($path);
        if ($existing !== null && ! $record->canReplace(ProofCloseoutRecord::fromArray($existing))) {
            throw new RuntimeException('The proof closeout record cannot replace its retained state.');
        }
        $this->store->write($path, $record->toArray());
    }

    /** Drop the attempt lease and record; the proof result and the log stay. */
    public function forgetAttempt(?AttemptPurpose $purpose = null): void
    {
        $purpose ??= $this->onlyAttemptPurpose();
        $this->store->delete($this->topologyPath($purpose));
        $this->store->delete($this->attemptPath($purpose));
    }

    public function log(string $line): void
    {
        $path = $this->paths->ensureParent('log');
        $entry = gmdate('Y-m-d\TH:i:s\Z').' '.str_replace(["\r", "\n"], ' ', $line)."\n";
        if (file_put_contents($path, $entry, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Unable to append to the issue log.');
        }
    }

    public function root(): string
    {
        return $this->paths->root();
    }

    /** @return array<array-key, mixed>|null */
    private function rawAttempt(AttemptPurpose $purpose): ?array
    {
        if ($purpose === AttemptPurpose::CandidateConvergence) {
            return $this->store->read(self::CANDIDATE_ATTEMPT);
        }
        if ($purpose === AttemptPurpose::Proof) {
            $proof = $this->store->read(self::PROOF_ATTEMPT);
            if ($proof !== null) {
                return $proof;
            }
        }

        $legacy = $this->store->read(self::ATTEMPT);

        return ($legacy['purpose'] ?? null) === $purpose->value ? $legacy : null;
    }

    private function onlyAttemptPurpose(): AttemptPurpose
    {
        $discovery = $this->hasAttempt(AttemptPurpose::Discovery);
        $proof = $this->hasAttempt(AttemptPurpose::Proof);
        $candidate = $this->hasAttempt(AttemptPurpose::CandidateConvergence);
        if (! $discovery && ! $proof && ! $candidate) {
            throw new RuntimeException("{$this->issue} has no active attempt.");
        }
        if (count(array_filter([$discovery, $proof, $candidate])) > 1) {
            throw new RuntimeException("{$this->issue} has multiple attempts; select one.");
        }

        return match (true) {
            $discovery => AttemptPurpose::Discovery,
            $proof => AttemptPurpose::Proof,
            default => AttemptPurpose::CandidateConvergence,
        };
    }

    private function attemptPath(AttemptPurpose $purpose): string
    {
        if ($purpose === AttemptPurpose::Discovery) {
            return self::ATTEMPT;
        }
        if ($purpose === AttemptPurpose::CandidateConvergence) {
            return self::CANDIDATE_ATTEMPT;
        }

        return $this->store->read(self::PROOF_ATTEMPT) !== null
        || ($this->store->read(self::ATTEMPT)['purpose'] ?? null) !== AttemptPurpose::Proof->value
            ? self::PROOF_ATTEMPT
            : self::ATTEMPT;
    }

    private function topologyPath(AttemptPurpose $purpose): string
    {
        if ($purpose === AttemptPurpose::Discovery) {
            return self::TOPOLOGY;
        }
        if ($purpose === AttemptPurpose::CandidateConvergence) {
            return self::CANDIDATE_TOPOLOGY;
        }

        return $this->attemptPath($purpose) === self::ATTEMPT ? self::TOPOLOGY : self::PROOF_TOPOLOGY;
    }

    /** Move a legacy proof out of discovery's stable file names before discovery uses them. */
    private function migrateLegacyProof(): void
    {
        $legacy = $this->store->read(self::ATTEMPT);
        if ($legacy === null || ($legacy['purpose'] ?? null) !== AttemptPurpose::Proof->value) {
            return;
        }
        if ($this->store->read(self::PROOF_ATTEMPT) !== null) {
            throw new RuntimeException('Both legacy and current proof attempt leases exist.');
        }
        $this->store->write(self::PROOF_ATTEMPT, $legacy);
        $topology = $this->store->read(self::TOPOLOGY);
        if ($topology !== null) {
            $this->store->write(self::PROOF_TOPOLOGY, $topology);
        }
        $this->store->delete(self::TOPOLOGY);
        $this->store->delete(self::ATTEMPT);
    }

    private function assertFingerprint(string $fingerprint): void
    {
        if (preg_match('/\A[0-9a-f]{64}\z/D', $fingerprint) !== 1) {
            throw new RuntimeException('The evidence fingerprint is invalid.');
        }
    }

    private function proofAttemptFromResult(): ?AttemptId
    {
        $attempt = $this->proof()['attempt_id'] ?? null;

        return is_string($attempt) ? new AttemptId($attempt) : null;
    }

    private function attemptEvidencePath(string $directory, AttemptId $attempt): string
    {
        return $directory.'/'.$attempt->value.'.json';
    }

    /** @param array<array-key, mixed> $value */
    private function writeImmutable(string $path, array $value): void
    {
        $existing = $this->store->read($path);
        if ($existing !== null && $existing !== $value) {
            throw new RuntimeException('Immutable proof evidence cannot be replaced.');
        }
        if ($existing === null) {
            $this->store->write($path, $value);
        }
    }
}
