<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\TaskGroup;

/**
 * ADR 0124: a Backlog or Todo group's planner thread works with the operator before the scheduler
 * runs the group, so the tick observes it here to keep its state and tokens current.
 */
final readonly class TaskPlannerObserver
{
    public function __construct(private AgentThreadObserver $threads) {}

    /** Observes every waiting group's planner thread and returns how many it read. */
    public function observe(): int
    {
        $groups = TaskGroup::query()
            ->where('execution_mode', TaskExecutionMode::Managed)
            ->whereIn('status', [TaskGroupStatus::Backlog, TaskGroupStatus::Todo])
            ->whereNotNull('reviewer_agent_thread_id')
            ->with('reviewerThread')
            ->orderBy('id')
            ->get();
        $observed = 0;

        foreach ($groups as $group) {
            $planner = $group->reviewerThread;

            if ($planner !== null) {
                $this->threads->observe($planner);
                $observed++;
            }
        }

        return $observed;
    }
}
