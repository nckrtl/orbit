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
    public function execute(?int $appId, ?TaskGroupStatus $status): Collection
    {
        $this->requireExtension->execute();

        return TaskGroup::query()
            ->with(['app', 'tasks', 'taskable'])
            ->when($appId !== null, static fn ($query) => $query->where('app_id', $appId))
            ->when($status instanceof TaskGroupStatus, static fn ($query) => $query->where('status', $status))
            ->orderByDesc('id')
            ->get();
    }
}
