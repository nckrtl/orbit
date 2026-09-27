<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AgentThread;
use App\Models\TaskGroup;

/**
 * ADR 0124 and ADR 0169: the tick reads the planner thread of a Backlog, Todo, running, or reviewing
 * group so its state and token count stay current. The read asks Jev nothing. A running group's
 * subtask reviewer is not the planner.
 */
final readonly class TaskPlannerObserver
{
    public function __construct(private AgentThreadObserver $threads) {}

    /** Observes every eligible group's planner thread and returns how many it read. */
    public function observe(): int
    {
        $groups = TaskGroup::query()
            ->where('execution_mode', TaskExecutionMode::Managed)
            ->where(function ($query): void {
                $query->where(function ($waiting): void {
                    $waiting->whereIn('status', [TaskGroupStatus::Backlog, TaskGroupStatus::Todo])
                        ->whereNotNull('reviewer_agent_thread_id');
                })->orWhere(function ($active): void {
                    $active->where('plan', true)->whereIn('status', [TaskGroupStatus::Running, TaskGroupStatus::Reviewing]);
                });
            })
            ->with('reviewerThread')
            ->orderBy('id')
            ->get();
        $observed = 0;

        foreach ($groups as $group) {
            $planner = $this->planner($group);

            if ($planner !== null) {
                $this->threads->observe($planner);
                $observed++;
            }
        }

        return $observed;
    }

    /** Before the first review the pointer is the planner. Afterwards the planner is the reviewer row with no subtask. */
    private function planner(TaskGroup $group): ?AgentThread
    {
        if (in_array($group->status, [TaskGroupStatus::Backlog, TaskGroupStatus::Todo], true)) {
            return $group->reviewerThread;
        }

        return AgentThread::query()
            ->where('task_group_id', $group->id)
            ->where('role', TaskThreadRole::Reviewer->value)
            ->whereNull('task_id')
            ->orderBy('id')
            ->first();
    }
}
