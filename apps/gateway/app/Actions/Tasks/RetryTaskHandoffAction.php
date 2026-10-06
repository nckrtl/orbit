<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Tasks\AgentThreadObserver;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskBaseBranchFetcher;
use App\Domain\Tasks\TaskCheckException;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskCommentType;
use App\Domain\Tasks\TaskDefaultBranchChecks;
use App\Domain\Tasks\TaskExecutionHold;
use App\Domain\Tasks\TaskExecutionMode;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskHandoffChecks;
use App\Domain\Tasks\TaskHandoffRetry;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskReviewBase;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use Illuminate\Support\Facades\DB;

final readonly class RetryTaskHandoffAction
{
    public function __construct(
        private TaskBaseBranchFetcher $bases,
        private TaskDefaultBranchChecks $green,
        private TaskCheckRunner $checks,
        private AgentThreadObserver $threads,
        private TaskHandoffChecks $handoffs,
    ) {}

    /** Persist intent before any candidate mutation. The execution lock excludes ordinary task work. */
    public function queue(Task $task): bool
    {
        return TaskExecutionHold::run($task->parent()->firstOrFail(), function () use ($task): bool {
            $task->refresh();
            $group = $task->parent()->with('taskable', 'project')->firstOrFail();
            if ($task->handoff_retry !== null || ! $this->eligible($group, $task)) {
                return false;
            }
            $check = $task->checks()->latest('id')->first();
            $receipt = $check instanceof TaskCheck ? TaskComment::query()->find($check->task_comment_id) : null;
            if (! $check instanceof TaskCheck || $check->kind !== TaskCheckKind::Handoff || $check->status !== TaskCheckStatus::Failed
                || $check->failed_step === 'invalid_deliverable' || ! $receipt instanceof TaskComment
                || $receipt->getRawOriginal('type') !== TaskCommentType::ReadyForReview->value || $receipt->completion_attempt !== $task->completion_attempt
                || $receipt->agent_thread_id !== $task->implementer_agent_thread_id || ! $this->receiptCurrent($task, $receipt)) {
                return false;
            }
            $instance = $group->taskable;
            if (! $instance instanceof Instance || $instance->checkout_path === '' || ! is_string($instance->branch) || $instance->branch === '') {
                return false;
            }
            try {
                $snapshot = $this->checks->snapshot($instance);
                if ($snapshot->head !== $check->head_before || $snapshot->tree !== $check->tree_before || $snapshot->branch !== $instance->branch || $snapshot->indexTree === null) {
                    return false;
                }
                $this->bases->fetchForTurn($group);
                $tip = $this->bases->defaultTip($group);
                if ($tip === $snapshot->head || ! $this->bases->isAncestor($group, $snapshot->head, $tip) || ! $this->green->green($group, $tip)) {
                    return false;
                }
            } catch (TaskCheckException|TaskPullRequestException) {
                return false;
            }
            $intent = new TaskHandoffRetry($check->id, $receipt->id, $instance->id, (int) $task->implementer_agent_thread_id,
                $group->reviewer_agent_thread_id, $task->completion_attempt, $snapshot->head, $snapshot->tree, $tip, TaskReviewBase::commit($task), $instance->checkout_path, $instance->branch, $snapshot->indexTree);

            return DB::transaction(function () use ($task, $group, $intent): bool {
                $lockedGroup = Task::topLevel()->lockForUpdate()->findOrFail($group->id);
                $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
                if ($locked->handoff_retry !== null || ! $intent->owns($lockedGroup, $locked)) {
                    return false;
                }
                $locked->update(['handoff_retry' => $intent->toArray()]);

                return true;
            });
        }) ?? false;
    }

    /** Resume only this persisted attempt. A lost start reply never authorizes another launch. */
    public function recover(Task $task): bool
    {
        return TaskExecutionHold::run($task->parent()->firstOrFail(), function () use ($task): bool {
            $task->refresh();
            $intent = TaskHandoffRetry::fromArray($task->handoff_retry);
            $group = $task->parent()->with('taskable', 'project')->firstOrFail();
            if ($intent === null || $intent->phase === 'finished' || ! $intent->owns($group, $task)) {
                return false;
            }
            if ($intent->phase === 'start_requested') {
                $existing = $task->checks()->where('id', '>', $intent->sourceCheckId)->where('task_comment_id', $intent->receiptId)
                    ->where('kind', TaskCheckKind::Handoff)->where('head_before', $intent->target)
                    ->where('tree_before', $intent->advancedTree)->latest('id')->first();
                $intent->checkId = $existing?->id;
            }
            $latest = $task->checks()->latest('id')->first();
            if (! $this->eligible($group, $task, $intent->checkId)
                || ! $latest instanceof TaskCheck || ! in_array($latest->id, [$intent->sourceCheckId, $intent->checkId], true)) {
                return false;
            }
            $receipt = $task->comments()->find($intent->receiptId);
            $instance = $group->taskable;
            if (! $receipt instanceof TaskComment || ! $instance instanceof Instance || $receipt->agent_thread_id !== $intent->implementerId
                || $receipt->completion_attempt !== $intent->attempt || $receipt->getRawOriginal('type') !== TaskCommentType::ReadyForReview->value || ! $this->receiptCurrent($task, $receipt)) {
                return false;
            }
            try {
                // Pin the checked SHA throughout recovery. Ref movement requires a new owner decision.
                $this->bases->fetchForTurn($group);
                if ($this->bases->defaultTip($group) !== $intent->target || ! $this->green->green($group, $intent->target)
                    || ! $this->bases->isAncestor($group, $intent->head, $intent->target)) {
                    return $this->blocked($task, $intent, 'Pinned default tip is no longer positively verified green.');
                }
                if (in_array($intent->phase, ['prepared', 'advancing'], true)) {
                    if (! $intent->owns($group->fresh() ?? $group, $task->fresh() ?? $task)) {
                        return false;
                    }
                    $intent->phase = 'advancing';
                    $this->save($task, $intent);
                    $snapshot = $this->bases->advanceCandidate($group, $intent->head, $intent->tree, $intent->target, $intent->indexTree);
                    if ($snapshot->head !== $intent->target || $snapshot->branch !== $intent->branch || $snapshot->indexTree === null) {
                        return $this->blocked($task, $intent, 'Candidate advancement returned an unexpected HEAD.');
                    }
                    $intent->advancedTree = $snapshot->tree;
                    $intent->advancedIndexTree = $snapshot->indexTree;
                    $intent->phase = 'advanced';
                    $this->save($task, $intent);
                }
                $snapshot = $this->checks->snapshot($instance);
                if ($snapshot->head !== $intent->target || $snapshot->tree !== $intent->advancedTree
                    || $snapshot->branch !== $intent->branch || $snapshot->indexTree !== $intent->advancedIndexTree
                    || ! $intent->owns($group->fresh() ?? $group, $task->fresh() ?? $task)) {
                    return $this->blocked($task, $intent, 'Candidate or ownership changed; preserve it for direction.');
                }
                if ($intent->phase === 'start_requested') {
                    $check = $task->checks()->where('id', '>', $intent->sourceCheckId)->where('task_comment_id', $intent->receiptId)
                        ->where('kind', TaskCheckKind::Handoff)->where('head_before', $intent->target)
                        ->where('tree_before', $intent->advancedTree)->latest('id')->first();
                    if (! $check instanceof TaskCheck) {
                        return $this->blocked($task, $intent, 'Prior check launch is uncertain; reconcile its process/result before any replacement.');
                    }
                    $intent->checkId = $check->id;
                    $intent->phase = 'running';
                    $this->save($task, $intent);
                }
                if ($intent->phase === 'advanced') {
                    if (! $this->eligible($group->fresh() ?? $group, $task->fresh() ?? $task)
                        || ! $intent->owns($group->fresh() ?? $group, $task->fresh() ?? $task)) {
                        return false;
                    }
                    $intent->phase = 'start_requested';
                    $this->save($task, $intent);
                    $check = $this->handoffs->start($group, $task, $receipt);
                    $intent->checkId = $check->id;
                    $intent->phase = 'running';
                    $this->save($task, $intent);
                }
                if ($intent->phase === 'running') {
                    // Reuse normal check reading, deliverable verification and review admission.
                    $done = app(TaskScheduler::class)->continueRecoveredHandoff($task, $intent);
                    if ($done && ($task->fresh()?->handoff_retry['phase'] ?? null) !== 'finished') {
                        $intent->phase = 'finished';
                        $this->save($task, $intent);
                    }

                    return true;
                }
            } catch (TaskCheckException|TaskPullRequestException $exception) {
                return $this->blocked($task, $intent, $exception->getMessage());
            }

            return false;
        }) ?? false;
    }

    private function eligible(Task $group, Task $task, ?int $ownCheck = null): bool
    {
        if ($group->execution_mode !== TaskExecutionMode::Managed || $group->status !== TaskGroupStatus::Running
            || $task->status !== TaskStatus::Running || ! $task->assistance_requested
            || $task->assistance_kind !== AssistanceKind::Failure || $group->assistance_kind === AssistanceKind::Direction
            || TaskExecutionHold::active($group) || $task->implementer_agent_thread_id === null
            || $task->resolution_delivered_comment_id !== null || $task->consult_comment_id !== null || $task->direction_relay_comment_id !== null
            || TaskCheck::query()->whereIn('task_id', $group->tasks()->select('id'))->where('status', TaskCheckStatus::Running)->when($ownCheck !== null, fn ($query) => $query->whereKeyNot($ownCheck))->exists()) {
            return false;
        }
        $records = AgentThread::query()->where('task_group_id', $group->id)->where(fn ($query) => $query->where('task_id', $task->id)->orWhere('id', $group->reviewer_agent_thread_id))->get();
        if (! $records->contains('id', $task->implementer_agent_thread_id)
            || ($group->reviewer_agent_thread_id !== null && ! $records->contains('id', $group->reviewer_agent_thread_id))) {
            return false;
        }
        foreach ($records as $thread) {
            $observed = $this->threads->observe($thread);
            if ($observed === null || ! in_array($observed->state, [AgentThreadState::Done, AgentThreadState::Idle], true)
                || $observed->inputRequests !== []) {
                return false;
            }
        }

        return true;
    }

    private function receiptCurrent(Task $task, TaskComment $receipt): bool
    {
        return ! $task->comments()->where('id', '>', $receipt->id)
            ->whereIn('type', [TaskCommentType::ReadyForReview->value, TaskCommentType::Approved->value, TaskCommentType::ChangesRequested->value])->exists();
    }

    private function save(Task $task, TaskHandoffRetry $intent): void
    {
        $task->update(['handoff_retry' => $intent->toArray()]);
    }

    private function blocked(Task $task, TaskHandoffRetry $intent, string $reason): bool
    {
        $intent->error = $reason;
        $this->save($task, $intent);

        return false;
    }
}
