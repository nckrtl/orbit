<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Data\Metrics\MetricsMutationData;

interface MetricsRoleManager
{
    public function enable(int $nodeId): MetricsMutationData;

    public function remove(bool $force, bool $purge): MetricsMutationData;

    public function enableExporter(int $nodeId): MetricsMutationData;

    public function disableExporter(int $nodeId): MetricsMutationData;
}
