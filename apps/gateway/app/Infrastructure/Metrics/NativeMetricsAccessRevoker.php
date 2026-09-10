<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\Metrics\MetricsAccessRevoker;
use App\Domain\Nodes\RoleName;
use App\Models\NodeRole;

final readonly class NativeMetricsAccessRevoker implements MetricsAccessRevoker
{
    public function __construct(
        private MetricsCaddyPublisher $caddy,
    ) {}

    public function revoke(): void
    {
        $metricsMayBePublished = NodeRole::query()
            ->where('role', RoleName::Metrics->value)
            ->exists();

        if (! $metricsMayBePublished) {
            return;
        }

        $this->caddy->reload();
    }
}
