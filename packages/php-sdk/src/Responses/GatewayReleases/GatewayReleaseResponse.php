<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\GatewayReleases;

use InvalidArgumentException;
use SensitiveParameter;
use stdClass;

/**
 * One Gateway release record. `release` and `sha` are null while a queued deploy names a short SHA.
 * `finished` is false while the record is `queued` or `running`.
 */
final readonly class GatewayReleaseResponse
{
    /**
     * @param  array<string, mixed>  $phases
     * @param  array<string, mixed>|null  $alert
     */
    public function __construct(
        public int $id,
        public ?string $release,
        public ?string $sha,
        public ?string $requested,
        public string $trigger,
        public bool $force,
        public string $outcome,
        public bool $finished,
        public bool $migrationsRan,
        public bool $retryable,
        public bool $cleanupPaused,
        public ?string $snapshot,
        public ?string $previous,
        public array $phases,
        public ?string $errorCode,
        public ?string $message,
        public int $durationMs,
        public ?array $alert,
        public ?string $createdAt,
        public ?string $updatedAt,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(#[SensitiveParameter] array $data, string $requestId): self
    {
        $id = $data['id'] ?? null;
        $trigger = $data['trigger'] ?? null;
        $outcome = $data['outcome'] ?? null;
        $finished = $data['finished'] ?? null;

        if (! is_int($id) || $id < 1 || ! is_string($trigger) || ! is_string($outcome) || ! is_bool($finished)) {
            throw new InvalidArgumentException('Gateway response contains an invalid release record.');
        }

        return new self(
            id: $id,
            release: self::text($data, 'release'),
            sha: self::text($data, 'sha'),
            requested: self::text($data, 'requested'),
            trigger: $trigger,
            force: ($data['force'] ?? false) === true,
            outcome: $outcome,
            finished: $finished,
            migrationsRan: ($data['migrations_ran'] ?? false) === true,
            retryable: ($data['retryable'] ?? false) === true,
            cleanupPaused: ($data['cleanup_paused'] ?? false) === true,
            snapshot: self::text($data, 'snapshot'),
            previous: self::text($data, 'previous'),
            phases: self::map($data['phases'] ?? null) ?? [],
            errorCode: self::text($data, 'error_code'),
            message: self::text($data, 'message'),
            durationMs: is_int($data['duration_ms'] ?? null) ? $data['duration_ms'] : 0,
            alert: self::map($data['alert'] ?? null),
            createdAt: self::text($data, 'created_at'),
            updatedAt: self::text($data, 'updated_at'),
            requestId: $requestId,
        );
    }

    public function succeeded(): bool
    {
        return $this->outcome === 'verified';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'release' => $this->release,
            'sha' => $this->sha,
            'requested' => $this->requested,
            'trigger' => $this->trigger,
            'force' => $this->force,
            'outcome' => $this->outcome,
            'finished' => $this->finished,
            'migrations_ran' => $this->migrationsRan,
            'retryable' => $this->retryable,
            'cleanup_paused' => $this->cleanupPaused,
            'snapshot' => $this->snapshot,
            'previous' => $this->previous,
            'phases' => $this->phases === [] ? new stdClass : $this->phases,
            'error_code' => $this->errorCode,
            'message' => $this->message,
            'duration_ms' => $this->durationMs,
            'alert' => $this->alert,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'request_id' => $this->requestId,
        ];
    }

    /** @param array<string, mixed> $data */
    private static function text(array $data, string $key): ?string
    {
        return is_string($data[$key] ?? null) ? $data[$key] : null;
    }

    /** @return array<string, mixed>|null */
    private static function map(#[SensitiveParameter] mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $map = [];

        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }
}
