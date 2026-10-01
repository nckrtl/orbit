<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\CoderSettleNotifier;
use App\Domain\Tasks\TaskAgentSpawner;
use App\Domain\Tasks\TaskCommentType;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskThreadRole;
use App\Models\Activity;
use App\Models\AgentThread;
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
                'task_group_id' => $task->parent_id,
                'task_id' => $task->id,
                'completion_attempt' => $task->completion_attempt,
                'posted_at' => Carbon::now(),
            ]);
            $rawType = $comment->getRawOriginal('type');
            $type = TaskCommentType::tryFrom(is_string($rawType) ? $rawType : '');

            $endedPullRequest = RequestEndedPullRequestAssistanceAction::isReason($task->assistance_reason)
                || RequestEndedPullRequestAssistanceAction::isReason($task->parent->assistance_reason);
            if ($type === TaskCommentType::AssistanceRequested && ! $endedPullRequest) {
                $task->update(['assistance_requested' => true, 'assistance_reason' => $comment->body]);
                $task->parent()->update(['assistance_requested' => true, 'assistance_reason' => $comment->body]);
                $this->log($task, $comment, 'assistance requested');
            }
            if ($type === TaskCommentType::Resolution && trim($comment->body) !== '' && $task->assistance_requested && ! $endedPullRequest) {
                $deliverResolution = true;
            }

            return $comment;
        });

        if ($deliverResolution) {
            $task->loadMissing('implementerThread');
            // A reviewing subtask is blocked on its own reviewer. The group pointer can still name an older thread.
            $reviewing = $task->status === TaskStatus::Reviewing;
            try {
                $thread = $reviewing ? $this->subtaskReviewer($task) : $task->implementerThread;
                if ($reviewing && $thread === null) {
                    $this->holdResolutionForFreshReviewer($task, $comment);
                } else {
                    if ($thread === null) {
                        throw new AgentDriverException('Blocked AgentThread is unavailable.');
                    }
                    $this->drivers->get($thread->driver)->send($thread, $comment->body);
                    $this->recordResolutionDelivered($task, $comment, $reviewing);
                }
            } catch (AgentDriverException) {
                DB::transaction(function () use ($task, $comment): void {
                    $this->log($task, $comment, 'resolution delivery failed');
                });
            }
        }

        $rawType = $comment->getRawOriginal('type');
        if (TaskCommentType::tryFrom(is_string($rawType) ? $rawType : '') === TaskCommentType::AssistanceRequested) {
            $this->notifier->assistance($task->parent()->firstOrFail(), $comment->body);
        }

        return $comment;
    }

    /** The reviewer thread for this subtask, preferring the one the group currently points at. */
    private function subtaskReviewer(Task $task): ?AgentThread
    {
        $reviewers = AgentThread::query()
            ->where('task_group_id', $task->parent_id)
            ->where('task_id', $task->id)
            ->where('role', TaskThreadRole::Reviewer->value)
            ->where('external_id', 'not like', TaskAgentSpawner::PendingPrefix.'%');
        $pointed = $task->parent()->value('reviewer_agent_thread_id');
        if (is_numeric($pointed)) {
            $match = (clone $reviewers)->whereKey((int) $pointed)->first();
            if ($match instanceof AgentThread) {
                return $match;
            }
        }

        return $reviewers->orderByDesc('id')->first();
    }

    /**
     * No reviewer exists for this subtask. Clear assistance without marking the review sent,
     * so the tick starts a fresh reviewer and its opening packet can carry this resolution.
     */
    private function holdResolutionForFreshReviewer(Task $task, TaskComment $comment): void
    {
        DB::transaction(function () use ($task, $comment): void {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            $parent = $locked->parent()->lockForUpdate()->firstOrFail();
            if (! $locked->assistance_requested
                || RequestEndedPullRequestAssistanceAction::isReason($locked->assistance_reason)
                || RequestEndedPullRequestAssistanceAction::isReason($parent->assistance_reason)) {
                return;
            }
            $comment->update(['review_attempt' => $locked->review_attempt]);
            $locked->update([
                'assistance_requested' => false,
                'assistance_reason' => null,
                'communication_failures' => 0,
                'review_reminder_attempt' => null,
                'review_reminder_input_id' => null,
            ]);
            $parent->update(['assistance_requested' => false, 'assistance_reason' => null]);
            $this->log($locked, $comment, 'resolution held for reviewer');
        });
    }

    private function recordResolutionDelivered(Task $task, TaskComment $comment, bool $reviewing): void
    {
        DB::transaction(function () use ($task, $comment, $reviewing): void {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            $parent = $locked->parent()->lockForUpdate()->firstOrFail();
            if (! $locked->assistance_requested
                || RequestEndedPullRequestAssistanceAction::isReason($locked->assistance_reason)
                || RequestEndedPullRequestAssistanceAction::isReason($parent->assistance_reason)) {
                return;
            }
            // The resolution is the reviewer's next request, so the tick must not send another.
            $attempt = $reviewing
                ? ['review_attempt' => $locked->review_attempt + 1, 'review_notified_attempt' => $locked->review_attempt + 1]
                : ['completion_attempt' => $locked->completion_attempt + 1, 'completion_reminder_attempt' => null, 'completion_reminder_input_id' => null];
            $locked->update([...$attempt, 'assistance_requested' => false, 'assistance_reason' => null, 'communication_failures' => 0, 'review_reminder_attempt' => null, 'review_reminder_input_id' => null, 'resolution_delivered_comment_id' => $comment->id]);
            $parent->update(['assistance_requested' => false, 'assistance_reason' => null]);
            $this->log($locked, $comment, 'resolution delivered');
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
