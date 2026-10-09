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
 * A change that starts with a subtask's exact title covers that subtask without Jev.
 * Jev classifies only the other subtasks, and its state lists only those, in the order of `task_ids`.
 * It reads the briefs and the change list. It cannot read code, so it checks coverage, not correctness.
 * Such a subtask counts as covered when Jev gives "true" a probability of at least one half.
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
        $unmatched = $subtasks->reject(static fn (Task $task): bool => self::titlePrefixed($task->title, $pullRequest->changes))->values();
        if ($unmatched->isEmpty()) {
            return [];
        }

        $state = [
            'group_title' => $group->title,
            'group_brief' => $group->brief,
            'subtasks' => $unmatched->map(static fn (Task $task): array => ['title' => $task->title, 'brief' => $task->brief])->all(),
            'pull_request' => $pullRequest->toArray(),
        ];
        $questions = [];
        foreach ($unmatched as $task) {
            $questions['subtask_'.$task->id] = new Boolean(
                'Does a change in the pull request change list deliver the subtask "'.$task->title.'"? Its brief: '.$task->brief,
                ['true' => 'A listed change delivers this subtask.', 'false' => 'No listed change delivers this subtask.'],
            );
        }
        $classification = Classification::of($state)->questions($questions);
        $answers = $this->jev->classify($classification, 'brief_coverage', [
            'task_group_id' => $group->id,
            'task_ids' => $unmatched->modelKeys(),
            'approval_comment_id' => $approvalCommentId,
            'approval_changes' => $approvalChanges,
        ], $questions, $state);

        $missing = [];
        foreach ($unmatched as $task) {
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

    /**
     * A trimmed change starts with the trimmed title, punctuation included, and the title ends there or before
     * a character that is not a letter or a digit. "Add export" covers "Add export: CSV", not "Add exporter".
     *
     * @param  list<string>  $changes
     */
    private static function titlePrefixed(string $title, array $changes): bool
    {
        $title = trim($title);
        if ($title === '') {
            return false;
        }
        foreach ($changes as $change) {
            $change = trim($change);
            if (str_starts_with($change, $title) && preg_match('/\A[\p{L}\p{N}]/u', substr($change, strlen($title))) !== 1) {
                return true;
            }
        }

        return false;
    }
}
