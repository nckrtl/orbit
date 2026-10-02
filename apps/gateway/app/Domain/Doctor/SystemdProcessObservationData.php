<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use InvalidArgumentException;

final readonly class SystemdProcessObservationData
{
    private const array ACTIVE_STATES = [
        'active', 'reloading', 'inactive', 'failed', 'activating', 'deactivating', 'maintenance', 'unknown',
    ];

    private const array SUB_STATES = [
        'dead', 'condition', 'start-pre', 'start', 'start-post', 'running', 'exited', 'reload',
        'reload-signal', 'reload-notify', 'stop', 'stop-watchdog', 'stop-sigterm', 'stop-sigkill',
        'stop-post', 'final-watchdog', 'final-sigterm', 'final-sigkill', 'failed',
        'dead-before-auto-restart', 'failed-before-auto-restart', 'dead-resources-pinned',
        'auto-restart', 'auto-restart-queued', 'cleaning', 'mounting',
    ];

    public function __construct(
        public string $activeState,
        public string $subState,
        public ?int $restarts,
    ) {
        if (
            ! in_array($activeState, self::ACTIVE_STATES, strict: true)
            || ! in_array($subState, self::SUB_STATES, strict: true)
            || ($restarts !== null && ($restarts < 0 || $restarts > 4294967295))
        ) {
            throw new InvalidArgumentException('Systemd process evidence must be bounded.');
        }
    }

    public function status(): ProcessInspectionStatus
    {
        return match ($this->activeState) {
            'active' => ProcessInspectionStatus::Active,
            'inactive' => ProcessInspectionStatus::Inactive,
            default => ProcessInspectionStatus::Other,
        };
    }

    public function isAutoRestart(): bool
    {
        return $this->activeState === 'activating' && $this->subState === 'auto-restart';
    }

    public function evidence(): string
    {
        $count = $this->restarts === null ? 'unavailable' : (string) $this->restarts;

        return "{$this->activeState}/{$this->subState}; NRestarts={$count}";
    }
}
