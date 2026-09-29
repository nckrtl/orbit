<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Tasks\TaskGroupStatus;
use App\Models\TaskGroup;
use Illuminate\Database\Eloquent\Collection;

final readonly class ListTaskGroupsAction
{
    public function __construct(private RequireTasksExtensionAction $requireExtension) {}

    /**
     * @return Collection<int, TaskGroup>
     */
    public function execute(?int $projectId, ?TaskGroupStatus $status): Collection
    {
        $this->requireExtension->execute();

        return TaskGroup::query()
            ->with(['project', 'tasks', 'taskable'])
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->when($status instanceof TaskGroupStatus, static fn ($query) => $query->where('status', $status))
            ->orderByDesc('id')
            ->get();
    }
}
