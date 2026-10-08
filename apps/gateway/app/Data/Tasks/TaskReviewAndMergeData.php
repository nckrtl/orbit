<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use App\Domain\Tasks\TaskMergeStatus;
use App\Models\Task;
use App\Models\TaskReviewedCommit;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** ADR 0203: the review-and-merge state of a task. Null on a task whose Project does not use the flow and that has no record. */
#[MapOutputName(SnakeCaseMapper::class)]
final class TaskReviewAndMergeData extends Data
{
    /** @param  list<TaskReviewedCommitData>  $reviewedCommits */
    public function __construct(
        public bool $enabled,
        public ?string $prBranch,
        public ?TaskMergeStatus $mergeStatus,
        public ?string $mergeReason,
        public ?string $mergedSha,
        public array $reviewedCommits,
    ) {}

    public static function fromModel(Task $group): ?self
    {
        $commits = $group->reviewedCommits()->get();
        $enabled = $group->reviewsBeforePush();
        if (! $enabled && $commits->isEmpty() && $group->pr_branch === null && $group->merge_status === null) {
            return null;
        }

        return new self(
            enabled: $enabled,
            prBranch: $group->pr_branch,
            mergeStatus: $group->merge_status,
            mergeReason: $group->merge_reason,
            mergedSha: $group->merged_sha,
            reviewedCommits: array_values($commits->map(static fn (TaskReviewedCommit $commit): TaskReviewedCommitData => TaskReviewedCommitData::fromModel($commit))->all()),
        );
    }
}
