<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\Tasks\TaskBriefCoverage;
use App\Domain\Tasks\TaskRunPullRequest;
use App\Domain\Tasks\TaskSessionClassificationException;
use App\Models\Task;
use App\Models\TaskGroup;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Responses\Data\BooleanAnswer;

/**
 * Jev reads the briefs and the change list only. It cannot read code, so it checks coverage, not correctness.
 * A subtask counts as covered when Jev gives "true" a probability of at least one half.
 */
final readonly class LaravelAiTaskBriefCoverage implements TaskBriefCoverage
{
    public function missing(TaskGroup $group, TaskRunPullRequest $pullRequest): array
    {
        $subtasks = $group->tasks()->orderBy('position')->orderBy('id')->get();
        $classification = Classification::of([
            'group_title' => $group->title,
            'group_brief' => $group->brief,
            'subtasks' => $subtasks->map(static fn (Task $task): array => ['title' => $task->title, 'brief' => $task->brief])->all(),
            'pull_request' => $pullRequest->toArray(),
        ]);
        foreach ($subtasks as $task) {
            $classification = $classification->question('subtask_'.$task->id, new Boolean(
                'Does a change in the pull request change list deliver the subtask "'.$task->title.'"? Its brief: '.$task->brief,
                ['true' => 'A listed change delivers this subtask.', 'false' => 'No listed change delivers this subtask.'],
            ));
        }
        $answers = Jev::classify($classification);

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
