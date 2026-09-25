<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AgentThread;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use App\Models\TaskGroup;
use Illuminate\Database\Eloquent\Model;

/**
 * Marks the task records that a model save changes, so `TaskBroadcasts` sends one notice for each
 * when the unit of work ends. Query-builder writes bypass it and mark their records themselves.
 */
final readonly class TaskBroadcastObserver
{
    public function __construct(private TaskBroadcasts $broadcasts) {}

    public function created(Model $model): void
    {
        match (true) {
            $model instanceof TaskGroup => $this->broadcasts->groupCreated($model->id),
            $model instanceof Task => $this->broadcasts->groupChanged($model->task_group_id),
            $model instanceof TaskCheck => $this->checkChanged($model),
            $model instanceof TaskComment => $this->broadcasts->commentCreated((int) $model->getKey()),
            $model instanceof AgentThread => $this->broadcasts->threadChanged($model->id),
            default => null,
        };
    }

    public function updated(Model $model): void
    {
        /** @var list<string> $columns */
        $columns = array_keys($model->getChanges());

        match (true) {
            $model instanceof TaskGroup => TaskBroadcasts::broadcastsGroupChange($columns) ? $this->broadcasts->groupChanged($model->id) : null,
            $model instanceof Task => TaskBroadcasts::broadcastsGroupChange($columns) ? $this->broadcasts->groupChanged($model->task_group_id) : null,
            $model instanceof TaskCheck => $this->checkChanged($model),
            $model instanceof AgentThread => TaskBroadcasts::broadcastsThreadChange($columns) ? $this->broadcasts->threadChanged($model->id) : null,
            default => null,
        };
    }

    public function deleted(Model $model): void
    {
        if ($model instanceof Task) {
            $this->broadcasts->groupChanged($model->task_group_id);
        }
    }

    private function checkChanged(TaskCheck $check): void
    {
        $groupId = Task::query()->whereKey($check->task_id)->value('task_group_id');

        if (is_int($groupId)) {
            $this->broadcasts->groupChanged($groupId);
        }
    }
}
