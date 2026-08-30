<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

final readonly class MetricsGeneratedFile
{
    public function __construct(
        public string $path,
        public ProtectedMetricsSecret $contents,
        public int $mode = 0o600,
        public string $owner = 'root',
        public string $group = 'root',
    ) {}
}
