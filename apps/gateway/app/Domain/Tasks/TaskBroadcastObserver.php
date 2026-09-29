<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AgentThread;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
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
            $model instanceof Task => $this->createdTask($model),
            $model instanceof TaskCheck => $this->checkChanged($model),
            $model instanceof TaskComment => $this->broadcasts->commentCreated($model->id),
            $model instanceof AgentThread => $this->broadcasts->threadChanged($model->id),
            default => null,
        };
    }

    public function updated(Model $model): void
    {
        $columns = array_keys($model->getChanges());

        match (true) {
            $model instanceof Task => TaskBroadcasts::broadcastsGroupChange($columns) ? $this->groupChanged($model) : null,
            $model instanceof TaskCheck => $this->checkChanged($model),
            $model instanceof AgentThread => TaskBroadcasts::broadcastsThreadChange($columns) ? $this->broadcasts->threadChanged($model->id) : null,
            default => null,
        };
    }

    public function deleted(Model $model): void
    {
        if ($model instanceof Task) {
            $this->groupChanged($model);
        }
    }

    private function createdTask(Task $task): void
    {
        if ($this->isTopLevel($task)) {
            $this->broadcasts->groupCreated($task->id);

            return;
        }

        $this->groupChanged($task);
    }

    private function groupChanged(Task $task): void
    {
        $this->broadcasts->groupChanged($this->isTopLevel($task) ? $task->id : $task->requireGroupId());
    }

    /**
     * A top-level insert never sets parent_id, so the key is absent until the row is reloaded.
     * A subtask always has the key set to its parent.
     */
    private function isTopLevel(Task $task): bool
    {
        if (! TaskSchema::merged($task->getConnection())) {
            return false;
        }

        $attributes = $task->getAttributes();

        return ! array_key_exists('parent_id', $attributes) || $attributes['parent_id'] === null;
    }

    private function checkChanged(TaskCheck $check): void
    {
        $groupId = Task::query()->whereKey($check->task_id)->value('task_group_id');

        if (is_int($groupId)) {
            $this->broadcasts->groupChanged($groupId);
        }
    }
}
