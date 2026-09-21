<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\AgentThread;
use App\Models\TaskGroup;
use Illuminate\Database\Eloquent\Collection;

final readonly class ShowAgentThreadsAction
{
    public function __construct(private RequireTasksExtensionAction $requireExtension) {}

    /** @return Collection<int, AgentThread> */
    public function execute(TaskGroup $group): Collection
    {
        $this->requireExtension->execute();

        return AgentThread::query()->where('task_group_id', $group->id)->orderBy('id')->get();
    }
}
