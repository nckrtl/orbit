<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Instance;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;

final readonly class TaskConcurrencyGuard
{
    public function canActivate(Task $group): bool
    {
        if (($group->task_compute ?? $group->project->task_compute) === TaskCompute::Vm) {
            return true;
        }
        $nodeId = $this->nodeId($group);

        if ($nodeId === null) {
            return true;
        }

        return $this->activeForNode($nodeId, $group->id) < TaskCeilings::PerNode;
    }

    public function activeForApp(int $projectId, ?int $exceptGroupId = null): int
    {
        return $this->activeQuery($exceptGroupId)
            ->where('project_id', $projectId)
            ->count();
    }

    public function activeForNode(int $nodeId, ?int $exceptGroupId = null): int
    {
        $instanceIds = Instance::query()
            ->where('node_id', $nodeId)
            ->select('id');

        return $this->activeQuery($exceptGroupId)
            ->where(fn (Builder $query) => $query->whereNull('task_compute')->orWhere('task_compute', TaskCompute::Shared))
            ->whereIn('taskable_type', Instance::morphTypes())
            ->whereIn('taskable_id', $instanceIds)
            ->count();
    }

    public function nodeId(Task $group): ?int
    {
        $taskable = $group->taskable;

        return $taskable instanceof Instance ? $taskable->node_id : null;
    }

    /** @return Builder<Task> */
    private function activeQuery(?int $exceptGroupId): Builder
    {
        return Task::topLevel()->where('execution_mode', TaskExecutionMode::Managed)
            ->whereIn('status', array_map(
                static fn (TaskGroupStatus $status): string => $status->value,
                TaskGroupStatus::active(),
            ))
            ->when(
                $exceptGroupId !== null,
                static fn (Builder $query): Builder => $query->where('id', '!=', $exceptGroupId),
            );
    }
}
