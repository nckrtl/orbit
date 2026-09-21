<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\TaskGroup;

final readonly class LocalTaskSettleMetricsCollector implements TaskSettleMetricsCollector
{
    public function __construct(private TaskGroupMetricsRefresher $metrics) {}

    public function collect(TaskGroup $group): TaskSettleMetrics
    {
        $refreshed = $this->metrics->refresh($group);

        return new TaskSettleMetrics(
            tokens: max(0, (int) ($refreshed->tokens ?? 0)),
            lineDiff: max(0, (int) ($refreshed->line_diff ?? 0)),
            durationMs: max(0, (int) ($refreshed->duration_ms ?? 0)),
        );
    }
}
