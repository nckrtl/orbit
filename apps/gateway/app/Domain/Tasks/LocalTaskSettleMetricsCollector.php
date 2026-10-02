<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;

final readonly class LocalTaskSettleMetricsCollector implements TaskSettleMetricsCollector
{
    public function __construct(private TaskGroupMetricsRefresher $metrics) {}

    public function collect(Task $group): TaskSettleMetrics
    {
        $refreshed = $this->metrics->refresh($group);

        return new TaskSettleMetrics(
            tokens: max(0, (int) ($refreshed->tokens ?? 0)),
            lineDiff: max(0, (int) ($refreshed->line_diff ?? 0)),
            durationMs: max(0, (int) ($refreshed->duration_ms ?? 0)),
            questions: max(0, (int) ($refreshed->questions ?? 0)),
            escalations: max(0, (int) ($refreshed->escalations ?? 0)),
        );
    }
}
