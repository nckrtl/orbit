<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\CoderSettleNotifier;
use App\Domain\Tasks\TaskAgentSpawner;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskCommentType;
use App\Domain\Tasks\TaskExecutionHold;
use App\Domain\Tasks\TaskQuestions;
use App\Domain\Tasks\TaskReviewPacketBuilder;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskThreadRole;
use App\Domain\Tasks\TaskTurnFetcher;
use App\Domain\Tasks\TaskTurnFetchNotice;
use App\Domain\Tasks\TaskTurnInstructions;
use App\Domain\Tasks\TaskTurnMode;
use App\Domain\Tasks\TaskTurnReceiptException;
use App\Domain\Tasks\TaskTurnReceipts;
use App\Models\Activity;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskComment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class StoreTaskCommentAction
{
    public function __construct(
        private AgentDriverRegistry $drivers,
        private CoderSettleNotifier $notifier,
        private TaskTurnReceipts $receipts,
        private TaskTurnFetcher $turnFetcher,
        private TaskTurnFetchNotice $fetchNotice,
        private TaskReviewPacketBuilder $reviewPackets,
        private RetryTaskBaselineAction $retryBaseline,
        private ResumeDeliverableCorrectionAction $correctionResume,
    ) {}

    /** @param array<string, mixed> $payload */
    public function execute(Task $task, array $payload, ?Node $actor = null, ?string $requestId = null): TaskComment
    {
        $deliverResolution = false;
        $deliverDirection = false;
        $retryBaselineQueued = false;
        $deliverCorrection = false;
        $comment = DB::transaction(function () use ($task, $payload, $actor, $requestId, &$deliverResolution, &$deliverDirection, &$retryBaselineQueued, &$deliverCorrection): TaskComment {
            $comment = TaskComment::query()->create([
                ...$payload,
                'task_group_id' => $task->parent_id,
                'task_id' => $task->id,
                'completion_attempt' => $task->completion_attempt,
                'posted_at' => Carbon::now(),
            ]);
            $rawType = $comment->getRawOriginal('type');
            $type = TaskCommentType::tryFrom(is_string($rawType) ? $rawType : '');

            $group = Task::topLevel()->lockForUpdate()->findOrFail($task->parent_id);
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            $endedPullRequest = TaskExecutionHold::active($group)
                || RequestEndedPullRequestAssistanceAction::isReason($task->assistance_reason)
                || RequestEndedPullRequestAssistanceAction::isReason($group->assistance_reason);
            if ($type === TaskCommentType::AssistanceRequested && ! $endedPullRequest) {
                TaskAssistance::apply($task, AssistanceKind::Direction, $comment->body, $comment->body, replaceDirection: true);
                TaskAssistance::apply($group, AssistanceKind::Direction, $comment->body, $comment->body, replaceDirection: true);
                TaskQuestions::recordOperator($task, $comment);
                $this->log($task, $comment, 'assistance requested');
            }
            if ($type === TaskCommentType::Resolution && trim($comment->body) !== '' && $task->assistance_requested && ! $endedPullRequest) {
                if ($task->deliverable_correction_check_id !== null && $group->assistance_kind === AssistanceKind::Direction
                    && $task->assistance_kind !== AssistanceKind::Direction) {
                    return $comment;
                }
                $needsCorrection = $task->status === TaskStatus::Running && $task->deliverable_correction_check_id !== null
                    && ($task->deliverable_correction_resume === null || $task->deliverable_correction_resume['state'] === 'pending');
                if ($needsCorrection) {
                    // Reserve the first authenticated resolution even when direction owns its delivery.
                    $this->correctionResume->reserve($task, $comment, $actor, $requestId);
                }
                $deliverCorrection = $needsCorrection
                    && $task->assistance_kind !== AssistanceKind::Direction && $group->assistance_kind !== AssistanceKind::Direction
                    && $task->direction_relay_comment_id === null && $task->consult_comment_id === null;
                if (! $deliverCorrection) {
                    $deliverResolution = $task->assistance_kind !== AssistanceKind::Direction;
                    $deliverDirection = $task->assistance_kind === AssistanceKind::Direction;
                    $retryBaselineQueued = $deliverResolution && $this->retryBaseline->queue($task, $comment);
                }
            }

            return $comment;
        });

        if ($deliverCorrection) {
            $this->correctionResume->execute($task);
        }

        if ($deliverDirection) {
            TaskExecutionHold::run($task->parent, fn () => $this->deliverDirection($task, $comment));
        }

        if ($deliverResolution) {
            TaskExecutionHold::run($task->parent, function () use ($task, $comment, $retryBaselineQueued): void {
                $task->loadMissing('implementerThread');
                // A reviewing subtask is blocked on its own reviewer. The group pointer can still name an older thread.
                $reviewing = $task->status === TaskStatus::Reviewing;
                try {
                    $thread = $reviewing ? $this->subtaskReviewer($task) : $task->implementerThread;
                    if ($retryBaselineQueued) {
                        $this->log($task, $comment, 'resolution queued baseline retry');
                        $this->retryBaseline->recover($task);
                    } elseif ($reviewing && $thread === null) {
                        $this->holdResolutionForFreshReviewer($task, $comment);
                    } else {
                        if ($thread === null) {
                            throw new AgentDriverException('Blocked AgentThread is unavailable.');
                        }
                        $this->turnFetcher->beforeTurn($task->parent()->with(['project', 'taskable'])->firstOrFail());
                        $this->drivers->get($thread->driver)->send($thread, $this->fetchNotice->apply($comment->body));
                        $this->recordResolutionDelivered($task, $comment, $reviewing);
                    }
                } catch (AgentDriverException) {
                    DB::transaction(function () use ($task, $comment): void {
                        $this->log($task, $comment, 'resolution delivery failed');
                    });
                }
            });
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
     * A direction resolution is a question, not a failure.
     * A reviewer who is already reading it answers next. A running subtask gets a relay, or a reviewer starts for one.
     */
    private function deliverDirection(Task $task, TaskComment $comment): void
    {
        $task->refresh();
        $reviewer = $this->subtaskReviewer($task);
        if ($task->status === TaskStatus::Reviewing && $reviewer instanceof AgentThread) {
            $this->sendDirectionReview($task, $comment, $reviewer);

            return;
        }
        if ($task->status === TaskStatus::Reviewing) {
            $this->commitHeldDirectionReview($task, $comment);

            return;
        }
        if ($task->status === TaskStatus::Running && $reviewer instanceof AgentThread) {
            $this->sendDirectionRelay($task, $comment, $reviewer);

            return;
        }
        if ($task->status === TaskStatus::Running) {
            TaskQuestions::attachResolution($task, $comment);

            return;
        }

        $this->recordResolutionDelivered($task, $comment, false);
    }

    private function sendDirectionReview(Task $task, TaskComment $comment, AgentThread $reviewer): void
    {
        $task->loadMissing('parent.taskable', 'parent.project');
        $instance = $task->parent->taskable;
        if (! $instance instanceof Instance) {
            return;
        }

        try {
            $this->turnFetcher->beforeTurn($task->parent);
            $this->receipts->prepare($instance, TaskThreadRole::Reviewer, $task->opensPullRequest(), $task->deliverableList(), $reviewer->id, new TaskTurnMode(causeRequired: true), $this->reviewPackets->reviewContext($task));
            $this->drivers->get($reviewer->driver)->send($reviewer, $this->fetchNotice->apply(trim($comment->body)."\n\n".TaskTurnInstructions::reviewer(final: $task->opensPullRequest(), deliverables: $task->deliverableList(), threadId: $reviewer->id)));
        } catch (AgentDriverException|TaskTurnReceiptException $exception) {
            report($exception);

            return;
        }

        $this->recordResolutionDelivered($task, $comment, true);
    }

    private function sendDirectionRelay(Task $task, TaskComment $comment, AgentThread $reviewer): void
    {
        $task->loadMissing('parent.taskable');
        $instance = $task->parent->taskable;
        if (! $instance instanceof Instance) {
            return;
        }

        try {
            $this->turnFetcher->beforeTurn($task->parent);
            $this->receipts->prepare($instance, TaskThreadRole::Reviewer, false, $task->deliverableList(), $reviewer->id, new TaskTurnMode(relay: true), $this->reviewPackets->reviewContext($task));
            $this->drivers->get($reviewer->driver)->send($reviewer, $this->fetchNotice->apply(trim($comment->body)."\n\n".TaskTurnInstructions::relay($reviewer->id)));
        } catch (AgentDriverException|TaskTurnReceiptException $exception) {
            report($exception);

            return;
        }

        DB::transaction(function () use ($task, $comment): void {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            if (! $locked->assistance_requested) {
                return;
            }
            $locked->update([
                ...TaskAssistance::cleared(),
                'communication_failures' => 0,
                'direction_relay_comment_id' => $comment->id,
                'resolution_delivered_comment_id' => $comment->id,
            ]);
            $locked->parent()->update(TaskAssistance::cleared());
            TaskQuestions::attachResolution($locked, $comment);
        });
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
                ...TaskAssistance::cleared(),
                'communication_failures' => 0,
                'review_reminder_attempt' => null,
                'review_reminder_input_id' => null,
            ]);
            $locked->parent()->update(TaskAssistance::cleared());
            $this->log($locked, $comment, 'resolution held for reviewer');
        });
    }

    /**
     * Link the direction question and clear both assistance rows in one comment-keyed transaction.
     * A retry of the same comment is a no-op once assistance is cleared.
     */
    public function commitHeldDirectionReview(Task $task, TaskComment $comment): void
    {
        DB::transaction(function () use ($task, $comment): void {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            if (! $locked->assistance_requested) {
                return;
            }
            $comment->update(['review_attempt' => $locked->review_attempt]);
            TaskQuestions::attachResolution($locked, $comment);
            $locked->update([
                ...TaskAssistance::cleared(),
                'communication_failures' => 0,
                'review_reminder_attempt' => null,
                'review_reminder_input_id' => null,
            ]);
            $locked->parent()->update(TaskAssistance::cleared());
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
            $locked->update([...$attempt, ...TaskAssistance::cleared(), 'communication_failures' => 0, 'review_reminder_attempt' => null, 'review_reminder_input_id' => null, 'resolution_delivered_comment_id' => $comment->id]);
            $parent->update(TaskAssistance::cleared());
            TaskQuestions::attachResolution($locked, $comment);
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
