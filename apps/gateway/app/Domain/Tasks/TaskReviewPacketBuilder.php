<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Instance;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use App\Models\TaskQuestion;

/**
 * Builds the review packet for one subtask from the group, the latest passed handoff, and the workspace diff.
 */
final readonly class TaskReviewPacketBuilder
{
    public function __construct(private TaskReviewDiff $diffs) {}

    public function build(Task $task, bool $continued, ?int $threadId = null): string
    {
        $task->loadMissing(['parent.project', 'parent.taskable']);
        $group = $task->parent;
        $start = TaskReviewBase::commit($task);
        $instance = $group->taskable;
        if (! $instance instanceof Instance) {
            throw new TaskReviewDiffException('The review diff could not be read.');
        }
        $diff = $this->diffs->read($instance, $start);
        $filesComplete = $diff['files_complete'];
        $diffAvailable = $diff['diff_available'];
        $handoff = $this->handoff($task);

        return new TaskReviewPacket(
            groupBrief: $group->brief,
            subtaskId: $task->id,
            subtaskTitle: $task->title,
            subtaskBrief: $task->brief,
            deliverables: $task->deliverableList(),
            approvals: $continued ? [] : $this->approvals($group, $task),
            diffFiles: $filesComplete ? $diff['files'] : [],
            diff: $diffAvailable ? $diff['diff'] : '',
            taskCheck: $group->project->taskCheckCommand(),
            handoffStatus: $handoff['status'],
            handoffExitCode: $handoff['exit'],
            evidence: $handoff['evidence'],
            startCommit: $start,
            continued: $continued,
            opensPullRequest: $task->opensPullRequest(),
            diffFilesComplete: $filesComplete,
            diffAvailable: $diffAvailable,
            diffCounts: $diff['summary'],
            resolution: $continued ? '' : $this->pendingResolution($task),
            threadId: $threadId,
            groupStartCommit: TaskReviewBase::groupStartCommit($group),
            consults: $continued ? [] : $this->consults($task),
        )->render();
    }

    /**
     * Answered consults for this subtask, oldest first. A fresh reviewer would otherwise lose them.
     *
     * @return list<array{question: string, answer: string}>
     */
    private function consults(Task $task): array
    {
        return array_values(TaskQuestion::query()
            ->where('subtask_id', $task->id)
            ->where('consult', true)
            ->where('status', QuestionStatus::Answered)
            ->orderBy('id')
            ->get()
            ->map(static fn (TaskQuestion $question): array => [
                'question' => $question->question,
                'answer' => is_string($question->answer) ? $question->answer : '',
            ])
            ->all());
    }

    /** The uncut context written to `.git/orbit/context.md` before a review turn. */
    public function reviewContext(Task $task): string
    {
        $task->loadMissing('parent');
        $group = $task->parent;

        return new TaskReviewContext(
            taskBrief: $group->brief,
            subtaskBrief: $task->brief,
            deliverables: $task->deliverableList(),
            approvals: $this->approvalBodies($group, $task),
            resolution: $this->pendingResolution($task),
            consults: $this->consults($task),
        )->render();
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
    private function approvals(Task $group, Task $task): array
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

    /** @return list<array{title: string, body: string}> */
    private function approvalBodies(Task $group, Task $task): array
    {
        $bodies = [];
        foreach ($this->approvals($group, $task) as $approval) {
            $bodies[] = ['title' => $approval['title'], 'body' => $approval['summary']];
        }

        return $bodies;
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
