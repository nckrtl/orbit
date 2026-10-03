<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Durable once-only source scope. All writes share the scheduler's group/task transaction. */
final readonly class TaskGitHubReviewConsumption
{
    public function consumed(Task $group, string $repository, int $number, int $reviewId): bool
    {
        return DB::table('task_github_review_consumptions')->where([
            'group_id' => $group->id, 'repository' => strtolower($repository),
            'pull_request_number' => $number, 'review_id' => $reviewId,
        ])->exists();
    }

    /** @param Collection<int, Task> $tasks */
    public function record(Task $group, Task $fixup, TaskReviewCandidate $candidate, Collection $tasks): void
    {
        DB::table('task_github_review_consumptions')->insert([
            'group_id' => $group->id,
            'repository' => strtolower($candidate->repository->owner.'/'.$candidate->repository->name),
            'pull_request_number' => $candidate->number,
            'review_id' => $candidate->review->id,
            'identity' => $fixup->fixup_problem,
            'operator_task_id' => self::window($tasks),
            'fixup_id' => $fixup->id,
            'digest' => $candidate->digest(),
            'packet' => $fixup->brief,
            'created_at' => now(),
        ]);
    }

    /** Missing work is never recreated and cannot buy budget. Extant work is counted by the scheduler.
     * @param  Collection<int, Task>  $tasks
     * @return array<string, int>
     */
    public function orphanCharges(Task $group, Collection $tasks): array
    {
        $window = self::window($tasks);
        $rows = DB::table('task_github_review_consumptions as consumption')
            ->leftJoin('tasks as fixup', 'fixup.id', '=', 'consumption.fixup_id')
            ->where('consumption.group_id', $group->id)->whereNull('fixup.id')
            ->where('consumption.operator_task_id', $window)->get(['consumption.identity']);
        $counts = [];
        foreach ($rows as $row) {
            if (is_string($row->identity)) {
                $counts[$row->identity] = ($counts[$row->identity] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /** All missing work needs assistance, even outside its original cap window or after dismissal.
     * @return array<string, string>
     */
    public function missingFixups(Task $group): array
    {
        $rows = DB::table('task_github_review_consumptions as consumption')
            ->leftJoin('tasks as fixup', 'fixup.id', '=', 'consumption.fixup_id')
            ->where('consumption.group_id', $group->id)->whereNull('fixup.id')
            ->orderBy('consumption.id')->get(['consumption.id', 'consumption.repository', 'consumption.pull_request_number', 'consumption.review_id']);
        $causes = [];
        foreach ($rows as $row) {
            $causes['missing:'.$row->id] = 'The consumed fixup for '.$row->repository.' PR #'.$row->pull_request_number.' review #'.$row->review_id.' is missing. Consumption and its original cap charge are retained. Restore the recorded work or append a scoped operator subtask; Orbit will not recreate it.';
        }

        return $causes;
    }

    /** @param Collection<int, Task> $tasks */
    private static function window(Collection $tasks): ?int
    {
        return $tasks->filter(static fn (Task $task): bool => $task->fixup_problem === null && $task->status === TaskStatus::Completed)
            ->sortBy(static fn (Task $task): array => [$task->position, $task->id])->last()?->id;
    }
}
