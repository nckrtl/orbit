<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

final readonly class MetricsConfigurationBundle
{
    /** @param non-empty-list<MetricsGeneratedFile> $files */
    public function __construct(
        public array $files,
        public string $publicHash,
    ) {}
}
