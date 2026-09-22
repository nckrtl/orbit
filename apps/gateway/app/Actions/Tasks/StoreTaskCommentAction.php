<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\Task;
use App\Models\TaskComment;
use Illuminate\Support\Carbon;

final readonly class StoreTaskCommentAction
{
    /** @param array<string, mixed> $payload */
    public function execute(Task $task, array $payload): TaskComment
    {
        return TaskComment::query()->create([
            ...$payload,
            'task_group_id' => $task->task_group_id,
            'task_id' => $task->id,
            'posted_at' => Carbon::now(),
        ]);
    }
}
