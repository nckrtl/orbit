<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\Metrics\MetricsAccessRevoker;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\NodeRole;

final readonly class NativeMetricsAccessRevoker implements MetricsAccessRevoker
{
    public function __construct(
        private MetricsCaddyPublisher $caddy,
    ) {}

    public function revoke(): void
    {
        $metricsIsActive = NodeRole::query()
            ->where('role', RoleName::Metrics->value)
            ->where('status', LifecycleStatus::Active->value)
            ->whereHas('node', static fn ($query) => $query->where(
                'status',
                LifecycleStatus::Active->value,
            ))
            ->exists();

        if (! $metricsIsActive) {
            return;
        }

        $this->caddy->reload();
    }
}
