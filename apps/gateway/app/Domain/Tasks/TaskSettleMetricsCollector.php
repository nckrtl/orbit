<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;

interface TaskSettleMetricsCollector
{
    public function collect(Task $group): TaskSettleMetrics;
}
