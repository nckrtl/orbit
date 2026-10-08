<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use App\Domain\Tasks\TaskReviewedCommitSource;
use App\Models\TaskReviewedCommit;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** ADR 0203: a commit Orbit fully reviewed for a task. */
#[MapOutputName(SnakeCaseMapper::class)]
final class TaskReviewedCommitData extends Data
{
    public function __construct(
        public string $sha,
        public TaskReviewedCommitSource $source,
        public ?int $reviewTaskId,
        public ?string $pushedAt,
        public ?int $githubReviewId,
        public string $recordedAt,
    ) {}

    public static function fromModel(TaskReviewedCommit $commit): self
    {
        return new self(
            sha: $commit->sha,
            source: $commit->source,
            reviewTaskId: $commit->review_task_id,
            pushedAt: $commit->pushed_at?->toIso8601String(),
            githubReviewId: $commit->github_review_id,
            recordedAt: $commit->created_at->toIso8601String(),
        );
    }
}
