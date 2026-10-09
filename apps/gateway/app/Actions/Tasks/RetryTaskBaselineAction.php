<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAgentSpawner;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskBaseBranchFetcher;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExecutionHold;
use App\Domain\Tasks\TaskExecutionMode;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskThreadRole;
use App\Models\AgentThread;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use Illuminate\Support\Facades\DB;

final readonly class RetryTaskBaselineAction
{
    public function __construct(private TaskBaseBranchFetcher $bases) {}

    /** Called in the comment transaction: retry intent must commit before any remote mutation. */
    public function queue(Task $task, TaskComment $comment): bool
    {
        $group = Task::topLevel()->lockForUpdate()->findOrFail($task->parent_id);
        $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
        $check = $this->failedBaseline($group, $locked);
        if (! $check instanceof TaskCheck) {
            return false;
        }
        // Keep the first pending request when two resolutions arrive before recovery finishes.
        if ($locked->resolution_delivered_comment_id === null || $check->task_comment_id === $locked->resolution_delivered_comment_id) {
            $locked->update(['resolution_delivered_comment_id' => $comment->id]);
        }

        return true;
    }

    /**
     * The failed baseline whose retry nothing has requested yet, read without a lock. The scheduler probes
     * GitHub only for this check, then queues the retry under the lock, which validates it again.
     */
    public function unrequested(Task $task): ?TaskCheck
    {
        $group = Task::topLevel()->find($task->parent_id);
        $fresh = Task::query()->find($task->id);
        $check = $group instanceof Task && $fresh instanceof Task ? $this->failedBaseline($group, $fresh) : null;

        return $check instanceof TaskCheck && ! $this->requested($fresh, $check) ? $check : null;
    }

    /**
     * Reconcile a committed request after a lost reset reply, rollback, or Gateway restart. The caller holds
     * the task execution lock. No database transaction is open during the fetch and reset; the bookkeeping
     * locks the rows again and validates the request again.
     */
    public function recover(Task $task): bool
    {
        $group = Task::topLevel()->findOrFail($task->parent_id);
        $fresh = Task::query()->findOrFail($task->id);
        $check = $this->failedBaseline($group, $fresh);
        if (! $check instanceof TaskCheck || ! $this->requested($fresh, $check)) {
            return false;
        }

        // Assistance keeps the scheduler out. The intent was committed separately. Repeating this reset is
        // safe while no agent started, and the reset refuses a workspace with manual work.
        try {
            $this->bases->fetchForTurn($group);
            $head = $this->bases->resetToDefault($group);
        } catch (TaskPullRequestException $exception) {
            throw new AgentDriverException($exception->getMessage(), previous: $exception);
        }

        return DB::transaction(function () use ($task, $head): bool {
            $group = Task::topLevel()->lockForUpdate()->findOrFail($task->parent_id);
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            $check = $this->failedBaseline($group, $locked);
            if (! $check instanceof TaskCheck || ! $this->requested($locked, $check)) {
                return false;
            }
            $locked->update([
                'subtask_start_commit' => $head,
                ...TaskAssistance::cleared(),
                'communication_failures' => 0,
            ]);
            $group->update(TaskAssistance::cleared());

            return true;
        });
    }

    private function requested(Task $task, TaskCheck $check): bool
    {
        return $task->resolution_delivered_comment_id !== null && $check->task_comment_id !== $task->resolution_delivered_comment_id;
    }

    /** A failed baseline is retryable only while the entire group remains untouched and its watched PR has not ended. */
    private function failedBaseline(Task $group, Task $task): ?TaskCheck
    {
        if ($group->execution_mode !== TaskExecutionMode::Managed || $group->status !== TaskGroupStatus::Running
            || TaskExecutionHold::active($group)
            || $task->status !== TaskStatus::Running || ! $task->assistance_requested
            || $task->assistance_kind === AssistanceKind::Direction || $group->assistance_kind === AssistanceKind::Direction) {
            return null;
        }
        $check = TaskCheck::query()->where('task_id', $task->id)
            ->where('kind', TaskCheckKind::Baseline->value)->latest('id')->first();
        if (! $check instanceof TaskCheck || $check->status !== TaskCheckStatus::Failed
            || TaskCheck::query()->where('task_id', $task->id)->where('status', TaskCheckStatus::Running->value)->exists()
            || Task::query()->where('parent_id', $group->id)->whereNotNull('implementer_agent_thread_id')->exists()
            || AgentThread::query()->where('task_group_id', $group->id)->where('role', TaskThreadRole::Implementer->value)
                ->where('external_id', 'not like', TaskAgentSpawner::PendingPrefix.'%')->exists()) {
            return null;
        }

        return $check;
    }
}
