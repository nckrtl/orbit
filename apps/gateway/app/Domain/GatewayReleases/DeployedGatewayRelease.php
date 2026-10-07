<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

/**
 * The outcome of one deploy or rollback. `verified` is the only success. `switched_back` means the
 * previous release is current again. `paused` means migrations ran and the new release stayed
 * current. `failed` means nothing that was current changed, or switch-back itself failed.
 */
final readonly class DeployedGatewayRelease
{
    /**
     * @param  array<string, mixed>  $phases
     */
    public function __construct(
        public string $id,
        public string $sha,
        public string $outcome,
        public string $trigger,
        public bool $migrationsRan,
        public ?string $previousId,
        public ?string $snapshotPath,
        public bool $cleanupPaused,
        public bool $retryable,
        public int $durationMs,
        public array $phases,
        public ?string $errorCode = null,
        public ?string $message = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->outcome === 'verified';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'release' => $this->id,
            'sha' => $this->sha,
            'outcome' => $this->outcome,
            'trigger' => $this->trigger,
            'migrations_ran' => $this->migrationsRan,
            'previous' => $this->previousId,
            'snapshot' => $this->snapshotPath,
            'cleanup_paused' => $this->cleanupPaused,
            'retryable' => $this->retryable,
            'duration_ms' => $this->durationMs,
            'phases' => $this->phases,
            'error_code' => $this->errorCode,
            'message' => $this->message,
        ];
    }
}
