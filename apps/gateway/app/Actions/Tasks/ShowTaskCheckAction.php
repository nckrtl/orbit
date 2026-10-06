<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\TaskCheckData;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCommentType;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;

final readonly class ShowTaskCheckAction
{
    public function execute(Task $group, Task $task, TaskCheck $check): TaskCheckData
    {
        abort_unless($group->parent_id === null && $task->parent_id === $group->id && $check->task_id === $task->id, 404);

        $receipt = null;
        if ($check->kind === TaskCheckKind::Probe && $check->task_comment_id !== null) {
            $comment = TaskComment::query()->where('task_id', $task->id)
                ->where('type', TaskCommentType::DeliverableProbe->value)->find($check->task_comment_id);
            if ($comment !== null) {
                $decoded = json_decode($comment->body, true, flags: JSON_THROW_ON_ERROR);
                $receipt = is_array($decoded) ? $decoded : null;
            }
        }

        return TaskCheckData::fromModel($check, $receipt);
    }
}
