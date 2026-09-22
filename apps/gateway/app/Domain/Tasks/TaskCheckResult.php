<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

final readonly class TaskCheckResult
{
    /**
     * @param  list<array<string, mixed>>  $checks
     * @param  array<string, array<string, mixed>>  $evidence
     */
    public function __construct(
        public string $fingerprint,
        public bool $passed,
        public array $checks,
        public array $evidence,
        public float $seconds,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromStored(array $data): self
    {
        if (! is_string($data['fingerprint'] ?? null) || ! is_bool($data['passed'] ?? null)
            || ! is_array($data['checks'] ?? null) || ! is_array($data['evidence'] ?? null)
            || ! is_numeric($data['seconds'] ?? null)) {
            throw new TaskSessionClassificationException('Stored task check result is incomplete.');
        }

        return new self($data['fingerprint'], $data['passed'], $data['checks'], $data['evidence'], (float) $data['seconds']);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['fingerprint' => $this->fingerprint, 'passed' => $this->passed, 'checks' => $this->checks,
            'evidence' => $this->evidence, 'seconds' => $this->seconds];
    }
}
