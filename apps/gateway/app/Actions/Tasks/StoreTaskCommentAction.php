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
    public function __construct(private AgentDriverRegistry $drivers, private RequestTaskAssistanceAction $assistance) {}

    /** @param array<string, mixed> $payload */
    public function execute(Task $task, array $payload): TaskComment
    {
        $deliverResolution = false;
        $comment = DB::transaction(function () use ($task, $payload, &$deliverResolution): TaskComment {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            $comment = TaskComment::query()->create([
                ...$payload,
                'task_group_id' => $task->task_group_id,
                'task_id' => $task->id,
                'completion_attempt' => $task->completion_attempt,
                'posted_at' => Carbon::now(),
            ]);
            $type = TaskCommentType::tryFrom((string) $comment->getRawOriginal('type'));

            if ($type === TaskCommentType::AssistanceRequested) {
                $this->assistance->execute($task, $comment->body, $comment);
            }
            if ($type === TaskCommentType::Resolution && trim($comment->body) !== '' && $task->assistance_requested) {
                $deliverResolution = true;
            }

            return $comment;
        });

        if ($deliverResolution) {
            $task->refresh()->loadMissing('implementerThread', 'taskGroup');
            try {
                $thread = $task->implementerThread;
                if ($thread === null) {
                    throw new AgentDriverException('Blocked AgentThread is unavailable.');
                }
                $this->drivers->get($thread->driver)->send($thread, $comment->body);
                DB::transaction(function () use ($task, $comment): void {
                    $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
                    if (! $locked->assistance_requested) {
                        return;
                    }
                    $locked->update(['assistance_requested' => false, 'assistance_reason' => null, 'communication_failures' => 0, 'completion_attempt' => $locked->completion_attempt + 1, 'completion_reminder_attempt' => null, 'completion_reminder_input_id' => null, 'review_reminder_attempt' => null, 'review_reminder_input_id' => null, 'resolution_delivered_comment_id' => $comment->id]);
                    $locked->taskGroup()->update(['assistance_requested' => false, 'assistance_reason' => null]);
                    $this->log($locked, $comment, 'resolution delivered');
                });
            } catch (AgentDriverException) {
                DB::transaction(function () use ($task, $comment): void {
                    $this->log($task, $comment, 'resolution delivery failed');
                });
            }
        }

        return $comment;
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
