<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/**
 * The compact verdict of one proof: `proved`, or `diagnosis` with the action
 * or phase that ended it. Full guest output is never stored; a failure keeps
 * the tail of the failing action's streams.
 *
 * @mago-expect lint:cyclomatic-complexity,excessive-parameter-list The result validates every field at construction.
 */
final readonly class ProofResult
{
    public const int TAIL_LIMIT = 4_096;

    /**
     * @param list<array{id:string,node:string,exit_code:int,stdout:string,stderr:string}> $actions
     */
    public function __construct(
        public string $issue,
        public AttemptId $attempt,
        public ProofStatus $status,
        public string $candidateSha,
        public array $actions,
        public ?string $error,
        public string $recordedAt,
    ) {
        TopologyTarget::assertIssue($issue);
        if (preg_match('/\A[0-9a-f]{40}\z/D', $candidateSha) !== 1) {
            throw new InvalidArgumentException('The proof candidate SHA is invalid.');
        }
        foreach ($actions as $action) {
            if (
                array_keys($action) !== ['id', 'node', 'exit_code', 'stdout', 'stderr']
                || ! is_string($action['id'])
                || ! in_array($action['node'], TopologyProfile::ROLES, true)
                || ! is_int($action['exit_code'])
                || ! is_string($action['stdout'])
                || ! is_string($action['stderr'])
            ) {
                throw new InvalidArgumentException('A proof action result is invalid.');
            }
        }
        if ($status === ProofStatus::Proved && ($error !== null || $this->failedAction() !== null)) {
            throw new InvalidArgumentException('A proved result cannot carry a failure.');
        }
    }

    public static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    /**
     * The action that ended the proof, with the tail of each stream; null when
     * no declared action failed.
     *
     * @return ?array{id:string,node:string,exit_code:int,stdout_tail:string,stderr_tail:string}
     */
    public function failedAction(): ?array
    {
        foreach (array_reverse($this->actions) as $action) {
            if ($action['exit_code'] !== 0) {
                return [
                    'id' => $action['id'],
                    'node' => $action['node'],
                    'exit_code' => $action['exit_code'],
                    'stdout_tail' => self::tail($action['stdout']),
                    'stderr_tail' => self::tail($action['stderr']),
                ];
            }
        }

        return null;
    }

    /** The trailing bytes of one stream, cut on a UTF-8 boundary. */
    public static function tail(string $value): string
    {
        if (strlen($value) <= self::TAIL_LIMIT) {
            return $value;
        }

        return mb_strcut($value, -self::TAIL_LIMIT);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $payload = [
            'status' => $this->status->value,
            'issue' => $this->issue,
            'attempt_id' => $this->attempt->value,
            'candidate_sha' => $this->candidateSha,
            'actions' => array_map(
                static fn (array $action): array => [
                    'id' => $action['id'],
                    'node' => $action['node'],
                    'exit_code' => $action['exit_code'],
                ],
                $this->actions,
            ),
            'recorded_at' => $this->recordedAt,
        ];
        $failed = $this->failedAction();
        if ($failed !== null) {
            $payload['failed_action'] = $failed;
        }
        if ($this->error !== null) {
            $payload['error'] = $this->error;
        }

        return $payload;
    }
}
