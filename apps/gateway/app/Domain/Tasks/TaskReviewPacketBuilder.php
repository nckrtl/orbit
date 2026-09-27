<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AppInstance;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use App\Models\TaskGroup;

/**
 * Builds the review packet for one subtask from the group, the latest passed handoff, and the workspace diff.
 */
final readonly class TaskReviewPacketBuilder
{
    public function __construct(private TaskReviewDiff $diffs) {}

    public function build(Task $task, bool $continued): string
    {
        $task->loadMissing(['taskGroup.app', 'taskGroup.taskable']);
        $group = $task->taskGroup;
        $start = $this->reviewBase($task, $group);
        $instance = $group->taskable;
        if (! $instance instanceof AppInstance) {
            throw new TaskReviewDiffException('The review diff could not be read.');
        }
        $diff = $this->diffs->read($instance, $start);
        $filesComplete = $diff['files_complete'];
        $diffAvailable = $diff['diff_available'];
        $handoff = $this->handoff($task);
        $branch = $group->app->default_branch;

        return new TaskReviewPacket(
            groupBrief: $group->brief,
            subtaskId: $task->id,
            subtaskTitle: $task->title,
            subtaskBrief: $task->brief,
            deliverables: $task->deliverableList(),
            approvals: $continued ? [] : $this->approvals($group, $task),
            diffFiles: $filesComplete ? $diff['files'] : [],
            diff: $diffAvailable ? $diff['diff'] : '',
            taskCheck: $group->app->taskCheckCommand(),
            handoffStatus: $handoff['status'],
            handoffExitCode: $handoff['exit'],
            evidence: $handoff['evidence'],
            startCommit: $start,
            continued: $continued,
            opensPullRequest: $task->opensPullRequest(),
            contract: TaskRunInstructions::contract(is_string($branch) ? $branch : null),
            diffFilesComplete: $filesComplete,
            diffAvailable: $diffAvailable,
            diffCounts: $diff['summary'],
            resolution: $continued ? '' : $this->pendingResolution($task),
        )->render();
    }

    /**
     * The recorded start commit, or a fallback when that read never succeeded.
     * A later subtask uses the previous approved commit. The first uses the workspace starting commit.
     */
    private function reviewBase(Task $task, TaskGroup $group): string
    {
        $recorded = $task->subtask_start_commit;
        if (self::isCommit($recorded)) {
            return $recorded;
        }
        $previous = $this->previousApprovedCommit($group, $task);
        if ($previous !== null) {
            return $previous;
        }
        $instance = $group->taskable;
        $starting = $instance instanceof AppInstance ? $instance->starting_commit : null;

        return self::isCommit($starting) ? $starting : '';
    }

    private function previousApprovedCommit(TaskGroup $group, Task $task): ?string
    {
        $earlier = Task::query()
            ->where('task_group_id', $group->id)
            ->where(function ($query) use ($task): void {
                $query->where('position', '<', $task->position)
                    ->orWhere(function ($query) use ($task): void {
                        $query->where('position', $task->position)->where('id', '<', $task->id);
                    });
            })
            ->pluck('id');
        if ($earlier->isEmpty()) {
            return null;
        }
        $commit = TaskComment::query()
            ->whereIn('task_id', $earlier)
            ->where('type', TaskCommentType::Approved->value)
            ->whereNotNull('commit_sha')
            ->latest('id')
            ->value('commit_sha');

        return self::isCommit($commit) ? $commit : null;
    }

    /** @phpstan-assert-if-true string $value */
    private static function isCommit(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{7,64}\z/i', $value) === 1;
    }

    /** A resolution held for this attempt because the subtask had no reviewer thread yet. */
    private function pendingResolution(Task $task): string
    {
        $body = TaskComment::query()
            ->where('task_id', $task->id)
            ->where('type', TaskCommentType::Resolution->value)
            ->where('review_attempt', $task->review_attempt)
            ->latest('id')
            ->value('body');

        return is_string($body) ? trim($body) : '';
    }

    /** @return list<array{title: string, summary: string}> */
    private function approvals(TaskGroup $group, Task $task): array
    {
        $approvals = [];
        foreach ($group->tasks()->orderBy('position')->orderBy('id')->get() as $earlier) {
            if ($earlier->id === $task->id || $earlier->position > $task->position || $earlier->status !== TaskStatus::Completed) {
                continue;
            }
            $summary = TaskComment::query()
                ->where('task_id', $earlier->id)
                ->where('type', TaskCommentType::Approved->value)
                ->latest('id')
                ->value('body');
            $approvals[] = ['title' => $earlier->title, 'summary' => is_string($summary) ? $summary : ''];
        }

        return $approvals;
    }

    /** @return array{status: string, exit: ?int, evidence: ?TaskDeliverableEvidence} */
    private function handoff(Task $task): array
    {
        $check = $task->checks()
            ->where('kind', TaskCheckKind::Handoff->value)
            ->where('status', TaskCheckStatus::Passed->value)
            ->latest('id')
            ->first();
        if (! $check instanceof TaskCheck) {
            return ['status' => TaskCheckStatus::Passed->value, 'exit' => null, 'evidence' => null];
        }

        return [
            'status' => TaskCheckStatus::Passed->value,
            'exit' => $check->exit_code,
            'evidence' => TaskDeliverableEvidence::fromArray($check->deliverable_evidence),
        ];
    }
}
