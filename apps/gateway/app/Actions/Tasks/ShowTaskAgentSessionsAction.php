<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\TaskAgentSession;
use App\Models\TaskGroup;
use Illuminate\Database\Eloquent\Collection;

final readonly class ShowTaskAgentSessionsAction
{
    public function __construct(private RequireTasksExtensionAction $requireExtension) {}

    /** @return Collection<int, TaskAgentSession> */
    public function execute(TaskGroup $group): Collection
    {
        $this->requireExtension->execute();

        return TaskAgentSession::query()->where('task_group_id', $group->id)->orderBy('id')->get();
    }
}
