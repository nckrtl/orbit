<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/**
 * The record of one proof attempt: which exact candidate ran on which attempt
 * topology, what the declared plan asked for, what every action observed, and
 * the topology proof verification. A `proved` record needs complete passing
 * evidence; a `diagnosis` record keeps whatever was observed before the failure.
 *
 * @phpstan-type ActionResult array{id:string,node:string,argv:list<string>,exit_code:int,stdout:string,stderr:string,started_at:string,finished_at:string}
 * @mago-expect lint:cyclomatic-complexity,excessive-parameter-list,kan-defect,too-many-methods Proof evidence is validated field by field at construction and summarized in one place.
 */
final readonly class ProofResult
{
    public const int SCHEMA = 2;

    /** Recorded stdout and stderr are each capped at this many bytes after redaction. */
    public const int OUTPUT_LIMIT = 16_384;

    /** The failure summary keeps this many trailing bytes of each captured stream. */
    public const int TAIL_LIMIT = 2_048;

    private const array KEYS = [
        'schema',
        'issue',
        'attempt_id',
        'status',
        'candidate_sha',
        'candidate_tree',
        'guest_script_hash',
        'proof_fixtures',
        'profile',
        'source',
        'plan',
        'setup_results',
        'acceptance_results',
        'verification',
        'post_deployment_actions',
        'recorded_at',
        'operation_id',
    ];

    private const array ACTION_RESULT_KEYS = [
        'id',
        'node',
        'argv',
        'exit_code',
        'stdout',
        'stderr',
        'started_at',
        'finished_at',
    ];

    private const string TIMESTAMP = '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z\z/D';

    /**
     * @param list<ActionResult> $setupResults
     * @param list<ActionResult> $acceptanceResults
     */
    public function __construct(
        public string $issue,
        public AttemptId $attempt,
        public ProofStatus $status,
        public string $candidateSha,
        public string $candidateTree,
        /** Null when the candidate never finished syncing; a proved result always has one. */
        public ?string $guestScriptHash,
        public SourceState $source,
        public ProofPlan $plan,
        public array $setupResults,
        public array $acceptanceResults,
        public VerificationReport $verification,
        public string $recordedAt,
        public string $operationId,
        /** Null when the fixtures never finished staging or the record predates fixture staging. */
        public ?ProofFixtures $fixtures = null,
    ) {
        TopologyTarget::assertIssue($issue);
        if (
            preg_match('/\A[0-9a-f]{40}\z/D', $candidateSha) !== 1
            || preg_match('/\A[0-9a-f]{40}\z/D', $candidateTree) !== 1
            || $guestScriptHash !== null
            && preg_match('/\A[0-9a-f]{64}\z/D', $guestScriptHash) !== 1
            || preg_match('/\A[0-9a-f]{32}\z/D', $operationId) !== 1
            || preg_match(self::TIMESTAMP, $recordedAt) !== 1
        ) {
            throw new InvalidArgumentException('The proof result identity is invalid.');
        }
        if (
            $source->hostSha !== $candidateSha
            || $source->guestSha !== $candidateSha
            || $source->dirty
            || $source->mounted
        ) {
            throw new InvalidArgumentException('The proof source must be the clean candidate commit.');
        }
        self::validateActionResults('setup', $plan->setup, $setupResults);
        self::validateActionResults('acceptance', $plan->acceptance, $acceptanceResults);

        if ($status === ProofStatus::Proved && ! $this->hasCompletePassingEvidence()) {
            throw new InvalidArgumentException('A proved result requires complete passing evidence.');
        }
    }

    public static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    /** The same evidence under another verdict; only `proved` to `diagnosis` is a legal transition. */
    public function withStatus(ProofStatus $status, string $recordedAt): self
    {
        return new self(
            $this->issue,
            $this->attempt,
            $status,
            $this->candidateSha,
            $this->candidateTree,
            $this->guestScriptHash,
            $this->source,
            $this->plan,
            $this->setupResults,
            $this->acceptanceResults,
            $this->verification,
            $recordedAt,
            $this->operationId,
            $this->fixtures,
        );
    }

    /**
     * The last action that ended the proof: its identity, exit code, and the tail
     * of each captured stream. Null when no declared action failed.
     *
     * @return ?array{id:string,node:string,exit_code:int,stdout_tail:string,stderr_tail:string}
     */
    public function failedAction(): ?array
    {
        foreach (array_reverse([...$this->setupResults, ...$this->acceptanceResults]) as $result) {
            if ($result['exit_code'] !== 0) {
                return [
                    'id' => $result['id'],
                    'node' => $result['node'],
                    'exit_code' => $result['exit_code'],
                    'stdout_tail' => self::tail($result['stdout']),
                    'stderr_tail' => self::tail($result['stderr']),
                ];
            }
        }

        return null;
    }

    /**
     * The record without the declared plan: command output never echoes the plan back.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return array_diff_key($this->toArray(), ['plan' => true]);
    }

    /** The trailing bytes of one stream, cut on a UTF-8 boundary. */
    public static function tail(string $value): string
    {
        if (strlen($value) <= self::TAIL_LIMIT) {
            return $value;
        }

        return mb_strcut($value, -self::TAIL_LIMIT);
    }

    private function hasCompletePassingEvidence(): bool
    {
        if ($this->guestScriptHash === null || ! $this->verification->passed) {
            return false;
        }
        if (
            count($this->setupResults) !== count($this->plan->setup)
            || count($this->acceptanceResults) !== count($this->plan->acceptance)
        ) {
            return false;
        }

        return array_all(
            [...$this->setupResults, ...$this->acceptanceResults],
            static fn (array $result): bool => $result['exit_code'] === 0,
        );
    }

    /**
     * Observed results are a prefix of the declared actions, in order, naming
     * exactly the declared node and argument vector.
     *
     * @param list<array{id:string,node:string,argv:list<string>,timeout_seconds:int}> $declared
     * @param array<array-key, mixed> $observed
     */
    private static function validateActionResults(string $section, array $declared, array $observed): void
    {
        if (! array_is_list($observed) || count($observed) > count($declared)) {
            throw new InvalidArgumentException("The {$section} action results do not match the declared actions.");
        }
        /** @mago-expect analysis:mixed-assignment Each observed result is validated one field at a time. */
        foreach ($observed as $index => $result) {
            $action = $declared[$index];
            if (
                ! is_array($result)
                || array_keys($result) !== self::ACTION_RESULT_KEYS
                || $result['id'] !== $action['id']
                || $result['node'] !== $action['node']
                || $result['argv'] !== $action['argv']
                || ! is_int($result['exit_code'])
                || ! is_string($result['stdout'])
                || ! is_string($result['stderr'])
                || ! is_string($result['started_at'])
                || preg_match(self::TIMESTAMP, $result['started_at']) !== 1
                || ! is_string($result['finished_at'])
                || preg_match(self::TIMESTAMP, $result['finished_at']) !== 1
            ) {
                throw new InvalidArgumentException("A {$section} action result does not match its declared action.");
            }
            if (strlen($result['stdout']) > self::OUTPUT_LIMIT || strlen($result['stderr']) > self::OUTPUT_LIMIT) {
                throw new InvalidArgumentException("A {$section} action result exceeds the recorded output limit.");
            }
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'issue' => $this->issue,
            'attempt_id' => $this->attempt->value,
            'status' => $this->status->value,
            'candidate_sha' => $this->candidateSha,
            'candidate_tree' => $this->candidateTree,
            'guest_script_hash' => $this->guestScriptHash,
            'proof_fixtures' => $this->fixtures?->toArray(),
            'profile' => TopologyProfile::NAME,
            'source' => $this->source->toArray(),
            'plan' => ['setup' => $this->plan->setup, 'acceptance' => $this->plan->acceptance],
            'setup_results' => $this->setupResults,
            'acceptance_results' => $this->acceptanceResults,
            'verification' => $this->verification->toArray(),
            'post_deployment_actions' => $this->plan->postDeploymentActions,
            'recorded_at' => $this->recordedAt,
            'operation_id' => $this->operationId,
        ];
    }

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        // A schema 1 record predates fixture staging and carries no fixture inventory.
        if (($value['schema'] ?? null) === 1 && ! array_key_exists('proof_fixtures', $value)) {
            $value = self::withSchemaTwoKeys($value);
        }
        if (
            array_keys($value) !== self::KEYS
            || $value['schema'] !== self::SCHEMA
            || $value['profile'] !== TopologyProfile::NAME
            || ! is_string($value['issue'])
            || ! is_string($value['attempt_id'])
            || ! is_string($value['status'])
            || ! is_string($value['candidate_sha'])
            || ! is_string($value['candidate_tree'])
            || $value['guest_script_hash'] !== null
            && ! is_string($value['guest_script_hash'])
            || $value['proof_fixtures'] !== null
            && ! is_array($value['proof_fixtures'])
            || ! is_array($value['source'])
            || ! is_array($value['plan'])
            || ! is_array($value['setup_results'])
            || ! is_array($value['acceptance_results'])
            || ! is_array($value['verification'])
            || ! is_array($value['post_deployment_actions'])
            || ! is_string($value['recorded_at'])
            || ! is_string($value['operation_id'])
        ) {
            throw new InvalidArgumentException('The proof result schema is invalid.');
        }
        $status = ProofStatus::tryFrom($value['status']);
        if ($status === null) {
            throw new InvalidArgumentException('The proof result status is invalid.');
        }
        $plan = ProofPlan::fromArray([
            ...$value['plan'],
            'post_deployment_actions' => $value['post_deployment_actions'],
        ]);

        /** @var list<ActionResult> $setupResults */
        $setupResults = $value['setup_results'];
        /** @var list<ActionResult> $acceptanceResults */
        $acceptanceResults = $value['acceptance_results'];

        return new self(
            $value['issue'],
            new AttemptId($value['attempt_id']),
            $status,
            $value['candidate_sha'],
            $value['candidate_tree'],
            $value['guest_script_hash'],
            SourceState::fromArray($value['source']),
            $plan,
            $setupResults,
            $acceptanceResults,
            VerificationReport::fromArray($value['verification']),
            $value['recorded_at'],
            $value['operation_id'],
            $value['proof_fixtures'] === null ? null : ProofFixtures::fromArray($value['proof_fixtures']),
        );
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function withSchemaTwoKeys(array $value): array
    {
        $upgraded = [];
        /** @mago-expect analysis:mixed-assignment The record keys are re-ordered without inspecting their values. */
        foreach ($value as $key => $field) {
            $upgraded[$key] = $key === 'schema' ? self::SCHEMA : $field;
            if ($key === 'guest_script_hash') {
                $upgraded['proof_fixtures'] = null;
            }
        }

        return $upgraded;
    }
}
