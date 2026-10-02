<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AgentThread;

final readonly class PrunePendingTaskThreads
{
    public function run(): void
    {
        AgentThread::query()->where('external_id', 'like', TaskAgentSpawner::PendingPrefix.'%')
            ->where(static function ($query): void {
                $query->whereIn('task_group_id', static function ($groups): void {
                    $groups->select('id')->from('tasks')->whereNull('parent_id')->whereIn('status', [TaskGroupStatus::Completed->value, TaskGroupStatus::Cancelled->value]);
                })->orWhereIn('task_id', static function ($tasks): void {
                    $tasks->select('id')->from('tasks')->whereNotNull('parent_id')->whereIn('status', [TaskStatus::Completed->value, TaskStatus::Cancelled->value]);
                });
            })->delete();

    }
}
