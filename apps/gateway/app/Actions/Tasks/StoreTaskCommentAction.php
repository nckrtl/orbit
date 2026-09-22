<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\TaskCommentType;
use App\Models\Activity;
use App\Models\Task;
use App\Models\TaskComment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class StoreTaskCommentAction
{
    public function __construct(private AgentDriverRegistry $drivers) {}

    /** @param array<string, mixed> $payload */
    public function execute(Task $task, array $payload): TaskComment
    {
        return DB::transaction(function () use ($task, $payload): TaskComment {
            $comment = TaskComment::query()->create([
                ...$payload,
                'task_group_id' => $task->task_group_id,
                'task_id' => $task->id,
                'completion_attempt' => $task->completion_attempt,
                'posted_at' => Carbon::now(),
            ]);
            $type = TaskCommentType::tryFrom((string) $comment->getRawOriginal('type'));

            if ($type === TaskCommentType::AssistanceRequested) {
                $task->update(['assistance_requested' => true, 'assistance_reason' => $comment->body]);
                $task->taskGroup()->update(['assistance_requested' => true, 'assistance_reason' => $comment->body]);
                $this->log($task, $comment, 'assistance requested');
            }
            if ($type === TaskCommentType::Resolution && trim($comment->body) !== '') {
                $task->loadMissing('implementerThread', 'taskGroup');
                if ($task->assistance_requested && $task->resolution_delivered_comment_id !== $comment->id) {
                    try {
                        $thread = $task->implementerThread;
                        if ($thread === null) {
                            throw new AgentDriverException('Blocked AgentThread is unavailable.');
                        }
                        $this->drivers->get($thread->driver)->send($thread, $comment->body);
                        $task->update(['assistance_requested' => false, 'assistance_reason' => null, 'communication_failures' => 0, 'completion_attempt' => $task->completion_attempt + 1, 'resolution_delivered_comment_id' => $comment->id]);
                        $task->update(['review_reminder_attempt' => null]);
                        $task->taskGroup()->update(['assistance_requested' => false, 'assistance_reason' => null]);
                        $this->log($task, $comment, 'resolution delivered');
                    } catch (AgentDriverException) {
                        $this->log($task, $comment, 'resolution delivery failed');
                    }
                }
            }

            return $comment;
        });
    }

    private function log(Task $task, TaskComment $comment, string $description): void
    {
        Activity::query()->create([
            'log_name' => 'tasks', 'description' => $description, 'subject_type' => $task::class,
            'subject_id' => $task->id, 'properties' => ['comment_id' => $comment->id, 'actor' => $comment->author],
            'request_id' => (string) Str::uuid(), 'command' => 'tasks:comment', 'status' => 'completed',
        ]);
    }
}
