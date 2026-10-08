<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\Tasks\TaskBriefCoverage;
use App\Domain\Tasks\TaskSessionClassificationException;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskTurnPullRequest;
use App\Models\Task;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Responses\Data\BooleanAnswer;

/**
 * Jev reads the briefs and the change list only. It cannot read code, so it checks coverage, not correctness.
 * A subtask counts as covered when Jev gives "true" a probability of at least one half.
 */
final readonly class LaravelAiTaskBriefCoverage implements TaskBriefCoverage
{
    public function __construct(private Jev $jev) {}

    /**
     * @param  list<string>|null  $approvalChanges
     * @return list<string>
     */
    public function missing(Task $group, TaskTurnPullRequest $pullRequest, ?int $approvalCommentId = null, ?array $approvalChanges = null): array
    {
        $subtasks = $group->tasks()
            ->whereNotIn('status', [TaskStatus::Cancelled, TaskStatus::Failed])
            ->withoutFinalReviews()
            ->orderBy('position')
            ->orderBy('id')
            ->get();
        $state = [
            'group_title' => $group->title,
            'group_brief' => $group->brief,
            'subtasks' => $subtasks->map(static fn (Task $task): array => ['title' => $task->title, 'brief' => $task->brief])->all(),
            'pull_request' => $pullRequest->toArray(),
        ];
        $questions = [];
        foreach ($subtasks as $task) {
            $questions['subtask_'.$task->id] = new Boolean(
                'Does a change in the pull request change list deliver the subtask "'.$task->title.'"? Its brief: '.$task->brief,
                ['true' => 'A listed change delivers this subtask.', 'false' => 'No listed change delivers this subtask.'],
            );
        }
        $classification = Classification::of($state)->questions($questions);
        $answers = $this->jev->classify($classification, 'brief_coverage', [
            'task_group_id' => $group->id,
            'task_ids' => $subtasks->modelKeys(),
            'approval_comment_id' => $approvalCommentId,
            'approval_changes' => $approvalChanges,
        ], $questions, $state);

        $missing = [];
        foreach ($subtasks as $task) {
            $answer = $answers['subtask_'.$task->id] ?? null;
            if (! $answer instanceof BooleanAnswer) {
                throw new TaskSessionClassificationException('TypeSafe Jev did not answer the coverage of subtask '.$task->id.'.');
            }
            if (! $answer->isTrue()) {
                $missing[] = $task->title;
            }
        }

        return $missing;
    }
}
