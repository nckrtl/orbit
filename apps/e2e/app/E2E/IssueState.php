<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\Value\AttemptId;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\OperationId;
use RuntimeException;

/**
 * The state of one issue's discovery and proof topologies, kept under `<worktree>/.e2e/`.
 *
 * `attempt.json` and `topology.json` hold discovery. `proof-attempt.json` and
 * `proof-topology.json` hold the fresh proof while discovery remains available.
 * `proof.json` is the last proof result and `log` is a plain-text line per
 * harness command. Legacy single proof leases remain readable and are migrated
 * when discovery is acquired.
 *
 * @mago-expect lint:cyclomatic-complexity,kan-defect,too-many-methods One state boundary owns every file under `.e2e/`.
 */
final readonly class IssueState
{
    public const string ATTEMPT = 'attempt.json';

    public const string TOPOLOGY = 'topology.json';

    public const string PROOF_ATTEMPT = 'proof-attempt.json';

    public const string PROOF_TOPOLOGY = 'proof-topology.json';

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

        return $this->hasAttempt(AttemptPurpose::Discovery) || $this->hasAttempt(AttemptPurpose::Proof);
    }

    /**
     * @return array{issue:string,attempt_id:string,purpose:string,operation_id:string,acquired_at:string}
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
        ) {
            throw new RuntimeException("The {$this->issue} attempt lease is invalid.");
        }

        /** @var array{issue:string,attempt_id:string,purpose:string,operation_id:string,acquired_at:string} $lease */
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

    public function writeAttempt(AttemptId $attempt, AttemptPurpose $purpose, OperationId $operation): void
    {
        if ($purpose === AttemptPurpose::Discovery) {
            $this->migrateLegacyProof();
        }
        $this->store->write($this->attemptPath($purpose), [
            'issue' => $this->issue,
            'attempt_id' => $attempt->value,
            'purpose' => $purpose->value,
            'operation_id' => $operation->value,
            'acquired_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
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

        return $topology;
    }

    /** The selected topology; its lease and record must name the same attempt. */
    public function requireTopology(?AttemptPurpose $purpose = null): FeatureTopology
    {
        $purpose ??= $this->onlyAttemptPurpose();
        $attempt = $this->attemptId($purpose);
        $topology = $this->topology($purpose) ?? throw new RuntimeException(
            "{$this->issue} has an active {$purpose->value} lease but no topology record.",
        );
        if ($topology->attempt->value !== $attempt->value) {
            throw new RuntimeException('The attempt lease and the topology record name different attempts.');
        }

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

        return (
            $this->store->read('equivalence/'.$pointer['fingerprint'].'.json') ?? throw new RuntimeException(
                'The equivalence report is missing.',
            )
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

        return (
            $proof !== null
            && ($proof['status'] ?? null) === 'proved'
            && $this->hasAttempt(AttemptPurpose::Proof)
            && ($proof['attempt_id'] ?? null) === $this->attempt(AttemptPurpose::Proof)['attempt_id']
        );
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
        if (! $discovery && ! $proof) {
            throw new RuntimeException("{$this->issue} has no active attempt.");
        }
        if ($discovery && $proof) {
            throw new RuntimeException("{$this->issue} has discovery and proof attempts; select one.");
        }

        return $discovery ? AttemptPurpose::Discovery : AttemptPurpose::Proof;
    }

    private function attemptPath(AttemptPurpose $purpose): string
    {
        if ($purpose === AttemptPurpose::Discovery) {
            return self::ATTEMPT;
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
