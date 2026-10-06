<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\TaskBaseBranchFetcher;
use App\Domain\Tasks\TaskCommentType;
use App\Domain\Tasks\TaskDefaultBranchChecks;
use App\Domain\Tasks\TaskExecutionHold;
use App\Domain\Tasks\TaskPullRequestException;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use Illuminate\Support\Facades\DB;

final readonly class RetryRedMainBaselineAction
{
    public const string COMMENT_PREFIX = 'Automatic baseline retry: ';

    public function __construct(
        private TaskBaseBranchFetcher $bases,
        private RetryTaskBaselineAction $retry,
        private TaskDefaultBranchChecks $checks,
    ) {}

    public function execute(Task $task): bool
    {
        $queued = DB::transaction(function () use ($task): bool {
            $group = Task::topLevel()->lockForUpdate()->findOrFail($task->parent_id);
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            $check = $this->retry->failedBaseline($group, $locked);
            if (! $check instanceof TaskCheck || TaskExecutionHold::active($group)
                || ($locked->resolution_delivered_comment_id !== null && $check->task_comment_id !== $locked->resolution_delivered_comment_id)
                || preg_match('/\A[0-9a-f]{40}\z/', $check->head_before) !== 1) {
                return false;
            }
            try {
                $this->bases->fetchForTurn($group);
                $tip = $this->bases->defaultTip($group);
                if ($tip === $check->head_before || ! $this->bases->isAncestor($group, $check->head_before, $tip)
                    || ! $this->checks->green($group, $tip)) {
                    return false;
                }
            } catch (TaskPullRequestException) {
                return false;
            }
            $comment = TaskComment::query()->create([
                'task_group_id' => $group->id,
                'task_id' => $locked->id,
                'completion_attempt' => $locked->completion_attempt,
                'type' => TaskCommentType::Resolution,
                'author' => 'gateway',
                'body' => self::COMMENT_PREFIX."{$check->head_before} → {$tip}. The default branch {$group->project->default_branch} has advanced and is green. Retry the Project baseline.",
                'posted_at' => now(),
            ]);

            return $this->retry->queue($locked, $comment);
        });
        if ($queued) {
            TaskExecutionHold::run($task->parent()->firstOrFail(), function () use ($task): void {
                try {
                    $this->retry->recover($task);
                } catch (AgentDriverException) {
                    // The committed resolution remains available for normal retry recovery.
                }
            });
        }

        return $queued;
    }
}
