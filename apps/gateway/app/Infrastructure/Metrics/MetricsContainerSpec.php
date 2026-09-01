<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

final readonly class MetricsContainerSpec
{
    /**
     * @param array<string, string> $labels
     * @param array<string, string> $volumeLabels
     * @param list<string> $command
     * @param list<string> $mounts
     * @param array<string, string> $environment
     * @param non-empty-list<string> $healthCommand
     * @param array<string, string> $logOptions
     * @mago-expect lint:excessive-parameter-list The immutable value keeps the complete bounded container specification.
     */
    public function __construct(
        public MetricsService $service,
        public string $image,
        public string $name,
        public string $volume,
        public array $labels,
        public array $volumeLabels,
        public array $command,
        public array $mounts,
        public array $environment,
        public array $healthCommand,
        public int $healthStartPeriodSeconds,
        public int $healthIntervalSeconds,
        public int $healthRetries,
        public string $logDriver,
        public array $logOptions,
        public string $specHash,
    ) {}
}
