<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

final readonly class MetricsPublication
{
    public function __construct(
        public string $hostname,
        public string $gatewayAddress,
        public string $metricsAddress,
    ) {}
}
