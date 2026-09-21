<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AppInstance;
use App\Models\TaskGroup;
use Illuminate\Database\Eloquent\Builder;

final readonly class TaskConcurrencyGuard
{
    public function canActivate(TaskGroup $group): bool
    {
        if ($this->activeForApp($group->app_id, $group->id) >= TaskCeilings::PerApp) {
            return false;
        }

        $nodeId = $this->nodeId($group);

        if ($nodeId === null) {
            return true;
        }

        return $this->activeForNode($nodeId, $group->id) < TaskCeilings::PerNode;
    }

    public function activeForApp(int $appId, ?int $exceptGroupId = null): int
    {
        return $this->activeQuery($exceptGroupId)
            ->where('app_id', $appId)
            ->count();
    }

    public function activeForNode(int $nodeId, ?int $exceptGroupId = null): int
    {
        $instanceIds = AppInstance::query()
            ->where('node_id', $nodeId)
            ->select('id');

        return $this->activeQuery($exceptGroupId)
            ->whereIn('taskable_type', AppInstance::morphTypes())
            ->whereIn('taskable_id', $instanceIds)
            ->count();
    }

    public function nodeId(TaskGroup $group): ?int
    {
        $taskable = $group->taskable;

        return $taskable instanceof AppInstance ? $taskable->node_id : null;
    }

    /** @return Builder<TaskGroup> */
    private function activeQuery(?int $exceptGroupId): Builder
    {
        return TaskGroup::query()
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
