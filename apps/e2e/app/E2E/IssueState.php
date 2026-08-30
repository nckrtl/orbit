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
 * The state of one issue's topology attempt, kept under `<worktree>/.e2e/`.
 *
 * `attempt.json` is the lease: which attempt is live, why it exists, and which
 * operation stamped its Incus resources. `topology.json` is the attempt record
 * (instances, network, mounts, source, verification). `proof.json` is the last
 * proof result and `log` is a plain-text line per harness command. Everything
 * dies with the worktree.
 *
 * @mago-expect lint:cyclomatic-complexity,too-many-methods One state boundary owns every file under `.e2e/`.
 */
final readonly class IssueState
{
    public const string ATTEMPT = 'attempt.json';

    public const string TOPOLOGY = 'topology.json';

    public const string PROOF = 'proof.json';

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

    /** Whether the worktree names a live attempt of this issue. */
    public function hasAttempt(): bool
    {
        return $this->store->read(self::ATTEMPT) !== null;
    }

    /**
     * @return array{issue:string,attempt_id:string,purpose:string,operation_id:string,acquired_at:string}
     */
    public function attempt(): array
    {
        $lease = $this->store->read(self::ATTEMPT) ?? throw new RuntimeException(
            "{$this->issue} has no active attempt.",
        );
        if (
            ($lease['issue'] ?? null) !== $this->issue
            || ! is_string($lease['attempt_id'] ?? null)
            || preg_match('/\A[0-9a-f]{32}\z/D', $lease['attempt_id']) !== 1
            || AttemptPurpose::tryFrom((string) ($lease['purpose'] ?? '')) === null
            || ! is_string($lease['operation_id'] ?? null)
            || preg_match('/\A[0-9a-f]{32}\z/D', $lease['operation_id']) !== 1
            || ! is_string($lease['acquired_at'] ?? null)
        ) {
            throw new RuntimeException("The {$this->issue} attempt lease is invalid.");
        }

        /** @var array{issue:string,attempt_id:string,purpose:string,operation_id:string,acquired_at:string} $lease */
        return $lease;
    }

    public function attemptId(): AttemptId
    {
        return new AttemptId($this->attempt()['attempt_id']);
    }

    public function operationId(): OperationId
    {
        return new OperationId($this->attempt()['operation_id']);
    }

    public function writeAttempt(AttemptId $attempt, AttemptPurpose $purpose, OperationId $operation): void
    {
        $this->store->write(self::ATTEMPT, [
            'issue' => $this->issue,
            'attempt_id' => $attempt->value,
            'purpose' => $purpose->value,
            'operation_id' => $operation->value,
            'acquired_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    public function topology(): ?FeatureTopology
    {
        $value = $this->store->read(self::TOPOLOGY);
        if ($value === null) {
            return null;
        }
        $topology = FeatureTopology::fromArray($value);
        if ($topology->target->issue !== $this->issue) {
            throw new RuntimeException('The topology record belongs to another issue.');
        }

        return $topology;
    }

    /** The topology of the live attempt; the lease and the record must name the same attempt. */
    public function requireTopology(): FeatureTopology
    {
        $attempt = $this->attemptId();
        $topology = $this->topology() ?? throw new RuntimeException(
            "{$this->issue} has an attempt lease but no topology record.",
        );
        if ($topology->attempt->value !== $attempt->value) {
            throw new RuntimeException('The attempt lease and the topology record name different attempts.');
        }

        return $topology;
    }

    public function writeTopology(FeatureTopology $topology): void
    {
        $this->store->write(self::TOPOLOGY, $topology->toArray());
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

    /** A proved attempt stays alive for review; `exec` and `sync` must not change it. */
    public function isProved(): bool
    {
        $proof = $this->proof();

        return (
            $proof !== null
            && ($proof['status'] ?? null) === 'proved'
            && $this->hasAttempt()
            && ($proof['attempt_id'] ?? null) === $this->attempt()['attempt_id']
        );
    }

    /** Drop the attempt lease and record; the proof result and the log stay. */
    public function forgetAttempt(): void
    {
        $this->store->delete(self::TOPOLOGY);
        $this->store->delete(self::ATTEMPT);
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
}
