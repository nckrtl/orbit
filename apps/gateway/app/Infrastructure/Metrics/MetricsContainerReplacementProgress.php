<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

final class MetricsContainerReplacementProgress
{
    public bool $hadPrevious = false;

    public bool $stopped = false;

    public bool $renamed = false;

    public bool $replacementStarted = false;

    public bool $backupDeleted = false;

    /**
     * @param array<string, string>|null $state
     * @param array<string, string>|null $backupState
     * @param array<string, string>|null $volumeState
     */
    public function __construct(
        public readonly MetricsContainerSpec $spec,
        public ?array $state,
        public ?array $backupState,
        public readonly ?array $volumeState,
    ) {}
}
