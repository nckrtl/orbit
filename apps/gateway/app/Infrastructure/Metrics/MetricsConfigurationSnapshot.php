<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

final readonly class MetricsConfigurationSnapshot
{
    /** @param array<string, MetricsGeneratedFile|null> $files */
    public function __construct(
        public bool $directoryExisted,
        public array $files,
    ) {}
}
