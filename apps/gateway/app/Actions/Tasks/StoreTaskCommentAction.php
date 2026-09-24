<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\CoderSettleNotifier;
use App\Domain\Tasks\TaskCommentType;
use App\Domain\Tasks\TaskStatus;
use App\Models\Activity;
use App\Models\Task;
use App\Models\TaskComment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class StoreTaskCommentAction
{
    public function __construct(private AgentDriverRegistry $drivers, private CoderSettleNotifier $notifier) {}

    /** @param array<string, mixed> $payload */
    public function execute(Task $task, array $payload): TaskComment
    {
        $deliverResolution = false;
        $comment = DB::transaction(function () use ($task, $payload, &$deliverResolution): TaskComment {
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
            if ($type === TaskCommentType::Resolution && trim($comment->body) !== '' && $task->assistance_requested) {
                $deliverResolution = true;
            }

            return $comment;
        });

        if ($deliverResolution) {
            $task->loadMissing('implementerThread', 'taskGroup.reviewerThread');
            // A task under review is blocked on the group's reviewer; otherwise on its implementer.
            $reviewing = $task->status === TaskStatus::Reviewing;
            try {
                $thread = $reviewing ? $task->taskGroup->reviewerThread : $task->implementerThread;
                if ($thread === null) {
                    throw new AgentDriverException('Blocked AgentThread is unavailable.');
                }
                $this->drivers->get($thread->driver)->send($thread, $comment->body);
                DB::transaction(function () use ($task, $comment, $reviewing): void {
                    $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
                    if (! $locked->assistance_requested) {
                        return;
                    }
                    // The resolution is the reviewer's next request, so the tick must not send another.
                    $attempt = $reviewing
                        ? ['review_attempt' => $locked->review_attempt + 1, 'review_notified_attempt' => $locked->review_attempt + 1]
                        : ['completion_attempt' => $locked->completion_attempt + 1, 'completion_reminder_attempt' => null, 'completion_reminder_input_id' => null];
                    $locked->update([...$attempt, 'assistance_requested' => false, 'assistance_reason' => null, 'communication_failures' => 0, 'review_reminder_attempt' => null, 'review_reminder_input_id' => null, 'resolution_delivered_comment_id' => $comment->id]);
                    $locked->taskGroup()->update(['assistance_requested' => false, 'assistance_reason' => null]);
                    $this->log($locked, $comment, 'resolution delivered');
                });
            } catch (AgentDriverException) {
                DB::transaction(function () use ($task, $comment): void {
                    $this->log($task, $comment, 'resolution delivery failed');
                });
            }
        }

        if (TaskCommentType::tryFrom((string) $comment->getRawOriginal('type')) === TaskCommentType::AssistanceRequested) {
            $this->notifier->assistance($task->taskGroup()->firstOrFail(), $comment->body);
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
