<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\TaskGroup;

interface TaskSettleMetricsCollector
{
    public function collect(TaskGroup $group): TaskSettleMetrics;
}
