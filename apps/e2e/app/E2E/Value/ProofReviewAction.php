<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/** One interactive action recorded separately from immutable proof evidence. */
final readonly class ProofReviewAction
{
    public const int SCHEMA = 1;

    /** @param list<string> $argv */
    public function __construct(
        public string $id,
        public string $type,
        public string $node,
        public bool $required,
        public string $status,
        public array $argv,
        public ?string $stdinSha256,
        public ?int $exitCode,
        public string $stdout,
        public string $stderr,
        public ?string $finding,
        public string $startedAt,
        public ?string $completedAt,
    ) {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D', $id) !== 1) {
            throw new InvalidArgumentException('The proof review action identity is invalid.');
        }
        if (! in_array($type, ['exec', 'shell'], true)) {
            throw new InvalidArgumentException('The proof review action type is invalid.');
        }
        if (! in_array($node, TopologyRecipe::extendedAppProd()->nodeKeys(), true)) {
            throw new InvalidArgumentException('The proof review action Node is invalid.');
        }
        if (! in_array($status, ['incomplete', 'passed', 'failed'], true)) {
            throw new InvalidArgumentException('The proof review action status is invalid.');
        }
        if (
            $type === 'exec' && $argv === []
            || $type === 'shell' && ($argv !== [] || $stdinSha256 !== null)
        ) {
            throw new InvalidArgumentException('The proof review action argv is invalid.');
        }
        if ($stdinSha256 !== null && preg_match('/\A[0-9a-f]{64}\z/D', $stdinSha256) !== 1) {
            throw new InvalidArgumentException('The proof review action stdin fingerprint is invalid.');
        }
        $this->assertTime($startedAt);
        if ($completedAt !== null) {
            $this->assertTime($completedAt);
            if ($completedAt < $startedAt) {
                throw new InvalidArgumentException('The proof review action completion time is invalid.');
            }
        }
        if ($finding !== null && $finding === '') {
            throw new InvalidArgumentException('The proof review action finding is invalid.');
        }
        if (
            $status === 'incomplete'
            && ($exitCode !== null || $stdout !== '' || $stderr !== '' || $finding !== null || $completedAt !== null)
        ) {
            throw new InvalidArgumentException('An incomplete proof review action cannot carry a result.');
        }
        if ($status !== 'incomplete' && $completedAt === null) {
            throw new InvalidArgumentException('A completed proof review action requires a completion time.');
        }
        if ($type === 'exec' && $status !== 'incomplete' && $exitCode === null) {
            throw new InvalidArgumentException('A completed proof review exec action requires an exit code.');
        }
        if ($status === 'passed' && $exitCode !== null && $exitCode !== 0) {
            throw new InvalidArgumentException('A passed proof review action cannot have a nonzero exit code.');
        }
        if ($status === 'failed' && $exitCode === 0) {
            throw new InvalidArgumentException('A failed proof review action cannot have a zero exit code.');
        }
    }

    /** @param list<string> $argv */
    public static function incomplete(
        string $id,
        string $type,
        string $node,
        bool $required,
        array $argv,
        ?string $stdinSha256,
        string $startedAt,
    ): self {
        return new self(
            $id,
            $type,
            $node,
            $required,
            'incomplete',
            $argv,
            $stdinSha256,
            null,
            '',
            '',
            null,
            $startedAt,
            null,
        );
    }

    public function complete(
        string $status,
        ?int $exitCode,
        string $stdout,
        string $stderr,
        ?string $finding,
        string $completedAt,
    ): self {
        if ($this->status !== 'incomplete') {
            throw new InvalidArgumentException('A completed proof review action cannot be completed again.');
        }

        return new self(
            $this->id,
            $this->type,
            $this->node,
            $this->required,
            $status,
            $this->argv,
            $this->stdinSha256,
            $exitCode,
            $stdout,
            $stderr,
            $finding,
            $this->startedAt,
            $completedAt,
        );
    }

    public function sameIdentity(self $other): bool
    {
        return $this->id === $other->id
            && $this->type === $other->type
            && $this->node === $other->node
            && $this->required === $other->required
            && $this->argv === $other->argv
            && $this->stdinSha256 === $other->stdinSha256
            && $this->startedAt === $other->startedAt;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'id' => $this->id,
            'type' => $this->type,
            'node' => $this->node,
            'required' => $this->required,
            'status' => $this->status,
            'argv' => $this->argv,
            'stdin_sha256' => $this->stdinSha256,
            'exit_code' => $this->exitCode,
            'stdout' => $this->stdout,
            'stderr' => $this->stderr,
            'finding' => $this->finding,
            'started_at' => $this->startedAt,
            'completed_at' => $this->completedAt,
        ];
    }

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        if (
            array_keys($value) !== [
                'schema',
                'id',
                'type',
                'node',
                'required',
                'status',
                'argv',
                'stdin_sha256',
                'exit_code',
                'stdout',
                'stderr',
                'finding',
                'started_at',
                'completed_at',
            ]
            || ($value['schema'] ?? null) !== self::SCHEMA
            || ! is_string($value['id'] ?? null)
            || ! is_string($value['type'] ?? null)
            || ! is_string($value['node'] ?? null)
            || ! is_bool($value['required'] ?? null)
            || ! is_string($value['status'] ?? null)
            || ! is_array($value['argv'] ?? null)
            || $value['stdin_sha256'] !== null && ! is_string($value['stdin_sha256'])
            || $value['exit_code'] !== null && ! is_int($value['exit_code'])
            || ! is_string($value['stdout'] ?? null)
            || ! is_string($value['stderr'] ?? null)
            || $value['finding'] !== null && ! is_string($value['finding'])
            || ! is_string($value['started_at'] ?? null)
            || $value['completed_at'] !== null && ! is_string($value['completed_at'])
        ) {
            throw new InvalidArgumentException('The proof review action schema is invalid.');
        }

        return new self(
            $value['id'],
            $value['type'],
            $value['node'],
            $value['required'],
            $value['status'],
            $value['argv'],
            $value['stdin_sha256'],
            $value['exit_code'],
            $value['stdout'],
            $value['stderr'],
            $value['finding'],
            $value['started_at'],
            $value['completed_at'],
        );
    }

    private function assertTime(string $time): void
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $time) !== 1) {
            throw new InvalidArgumentException('A proof review action time is invalid.');
        }
    }
}
