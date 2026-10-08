<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Shared\StoredInteger;
use App\Models\Activity;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskReviewedCommit;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * ADR 0203: the final review of a review-and-merge task. Orbit appends it when the task holds approved work
 * that no final review approved. It has no implementer. Its approval is the only step that pushes.
 */
final readonly class TaskFinalReview
{
    public const string Title = 'Final review';

    public const string DeliverableId = 'final-review';

    /** The fixup identity of the subtask that addresses a final review's findings. */
    public const string FixupProblem = 'final-review';

    public const string FixupTitle = 'Address final review';

    /** Final-review fixups in one window before the task asks for assistance. */
    public const int FixupLimit = 3;

    /** Assistance set when someone other than Orbit pushed to an Orbit task branch. */
    public const string UnreviewedHeadPrefix = 'The pull request head was not reviewed by Orbit: ';

    /** Assistance set when final reviews keep requesting changes. */
    public const string FixupCapPrefix = 'The final review keeps requesting changes: ';

    /** The brief of a final review fixup holds at most this many characters of findings. */
    private const int FindingsLimit = 7000;

    public static function brief(Task $group): string
    {
        $default = $group->project->default_branch ?? 'the default branch';
        if ($group->reviewsIncomingPullRequest()) {
            return 'Review pull request '.$group->pr_url.' as a whole before Orbit approves it on GitHub and merges it. '
                .'The diff runs from its merge base with origin/'.$default.' to the pull request head. '
                .'Approve only when the complete change is correct, tested, documented, and ready to merge as it is. '
                .'Otherwise request changes and list each finding with its file and what must change. Orbit applies the findings in a new subtask.';
        }

        return 'Review the whole task branch before Orbit pushes it'.(is_string($group->pr_url) && $group->pr_url !== '' ? ' to '.$group->pr_url : ' and opens the pull request').'. '
            .'The diff runs from its merge base with origin/'.$default.' to HEAD, and covers every approved subtask. '
            .'Approve only when the complete change meets the task brief, is tested and documented, and is ready to merge. '
            .'Otherwise request changes and list each finding with its file and what must change. Orbit applies the findings in a new subtask.';
    }

    /** @return list<array<string, string>> */
    public static function deliverables(): array
    {
        return [[
            'id' => self::DeliverableId,
            'type' => 'review',
            'description' => 'The whole change meets the brief and is ready to merge as it is.',
        ]];
    }

    public static function fixupBrief(Task $finalReview, TaskComment $receipt): string
    {
        $findings = trim($receipt->body);
        if (mb_strlen($findings) > self::FindingsLimit) {
            $findings = mb_substr($findings, 0, self::FindingsLimit)."\n\n[Cut. The full findings are comment #".$receipt->id.' on subtask #'.$finalReview->id.'.]';
        }

        return 'Address every finding of final review subtask #'.$finalReview->id.'. Resolve each one, or explain in your handoff why it does not apply within the task brief. '
            ."Do not rebase and do not force-push.\n\nFindings:\n\n".$findings;
    }

    /** @return list<array<string, string>> */
    public static function fixupDeliverables(?string $taskCheck): array
    {
        $deliverables = [];
        if ($taskCheck !== null) {
            $deliverables[] = ['id' => 'project-check', 'type' => 'command', 'description' => 'The Project task check passes.', 'command' => $taskCheck, 'directory' => '.'];
        }
        $deliverables[] = ['id' => 'final-review-findings', 'type' => 'review', 'description' => 'Every finding of the final review is addressed or explicitly resolved within the task brief.'];

        return $deliverables;
    }

    /**
     * The commit of the latest approved subtask that is not a final review, or null when none was committed.
     * A final review commits nothing, so its approval names the HEAD it reviewed instead.
     */
    public static function latestApprovedCommit(Task $group): ?string
    {
        $commit = TaskComment::query()
            ->where('task_group_id', $group->id)
            ->where('type', TaskCommentType::Approved)
            ->whereNotNull('commit_sha')
            ->whereHas('task', static fn ($query) => $query->withoutFinalReviews())
            ->latest('id')
            ->value('commit_sha');

        return is_string($commit) && $commit !== '' ? $commit : null;
    }

    /**
     * The approved commit that cancel pushes before it removes the workspace, or null. A review-and-merge task
     * pushes only a commit a final review approved, so unreviewed approved work is not pushed.
     */
    public static function cancelPushCommit(Task $group): ?string
    {
        $commit = TaskComment::query()
            ->where('task_group_id', $group->id)
            ->where('type', TaskCommentType::Approved)
            ->whereNotNull('commit_sha')
            ->latest('id')
            ->value('commit_sha');
        if (! is_string($commit) || $commit === '') {
            return null;
        }

        return ! $group->reviewsBeforePush() || self::isReviewed($group, $commit) ? $commit : null;
    }

    public static function isReviewed(Task $group, string $sha): bool
    {
        return TaskReviewedCommit::query()->where('task_id', $group->id)->where('sha', $sha)->exists();
    }

    /** Whether the task holds approved work that no final review approved. */
    public static function hasUnreviewedWork(Task $group): bool
    {
        $commit = self::latestApprovedCommit($group);

        return $commit !== null && ! self::isReviewed($group, $commit);
    }

    /**
     * Whether Orbit must append a final review: the task reviews before it pushes, no subtask is open, and its
     * latest approved work has no final review.
     *
     * @param  Collection<int, Task>  $tasks
     */
    public static function due(Task $group, Collection $tasks): bool
    {
        return $group->reviewsBeforePush()
            && ! $tasks->contains(static fn (Task $task): bool => in_array($task->status, [TaskStatus::Todo, TaskStatus::Running, TaskStatus::Reviewing], true))
            && self::hasUnreviewedWork($group);
    }

    /**
     * Final-review fixups after the latest completed operator subtask. Final reviews themselves are not operator work.
     *
     * @param  Collection<int, Task>  $tasks
     */
    public static function fixupsInWindow(Collection $tasks): int
    {
        $ordered = $tasks->sortBy(static fn (Task $task): array => [$task->position, $task->id])->values();
        $boundary = $ordered
            ->filter(static fn (Task $task): bool => $task->fixup_problem === null && ! $task->isFinalReview() && $task->status === TaskStatus::Completed)
            ->max('position');

        return $ordered->filter(static fn (Task $task): bool => $task->fixup_problem === self::FixupProblem
            && ($boundary === null || $task->position > $boundary))->count();
    }

    /** @param  Collection<int, Task>  $tasks */
    public static function nextPosition(Collection $tasks): int
    {
        return StoredInteger::fromOrZero($tasks->max('position')) + 1;
    }

    /** @param  array<string, mixed>  $properties */
    public static function log(Task $task, string $description, array $properties = []): void
    {
        Activity::query()->create([
            'log_name' => 'tasks', 'description' => $description, 'subject_type' => $task::class,
            'subject_id' => $task->id, 'properties' => $properties,
            'request_id' => (string) Str::uuid(), 'command' => 'tasks:tick', 'status' => 'completed',
        ]);
    }
}
