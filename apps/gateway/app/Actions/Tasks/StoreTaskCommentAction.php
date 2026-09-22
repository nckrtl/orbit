<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Models\Task;
use App\Models\TaskComment;
use Illuminate\Support\Carbon;

final readonly class StoreTaskCommentAction
{
    public function __construct(private AgentDriverRegistry $drivers) {}

    /** @param array<string, mixed> $payload */
    public function execute(Task $task, array $payload): TaskComment
    {
        $comment = TaskComment::query()->create([
            ...$payload,
            'task_group_id' => $task->task_group_id,
            'task_id' => $task->id,
            'completion_attempt' => $task->completion_attempt,
            'posted_at' => Carbon::now(),
        ]);

        if ($comment->type === 'assistance_requested') {
            $task->update(['assistance_requested' => true, 'assistance_reason' => $comment->body]);
            $task->taskGroup()->update(['assistance_requested' => true, 'assistance_reason' => $comment->body]);
        }
        if ($comment->type === 'resolution' && trim($comment->body) !== '') {
            $task->loadMissing('implementerThread', 'taskGroup');
            if ($task->resolution_delivered_comment_id !== $comment->id) {
                try {
                    $thread = $task->implementerThread;
                    if ($thread === null) {
                        throw new AgentDriverException('Blocked AgentThread is unavailable.');
                    }
                    $this->drivers->get($thread->driver)->send($thread, $comment->body);
                    $task->update(['assistance_requested' => false, 'assistance_reason' => null, 'communication_failures' => 0, 'completion_attempt' => $task->completion_attempt + 1, 'resolution_delivered_comment_id' => $comment->id]);
                    $task->taskGroup()->update(['assistance_requested' => false, 'assistance_reason' => null]);
                } catch (AgentDriverException) {
                    // Preserve the resolution and visible blocked state for an idempotent retry.
                }
            }
        }

        return $comment;
    }
}
