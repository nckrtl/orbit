<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use App\Domain\Tasks\TaskRunPullRequest;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** The pull request description the reviewer's final approval proposed. */
#[MapOutputName(SnakeCaseMapper::class)]
final class TaskCommentPullRequestData extends Data
{
    public function __construct(
        public string $summary,
        /** @var list<string> */
        public array $changes,
        /** @var list<string> */
        public array $breaking,
    ) {}

    public static function fromStored(mixed $stored): ?self
    {
        $pullRequest = TaskRunPullRequest::fromArray($stored);

        return $pullRequest === null ? null : new self(
            summary: $pullRequest->summary,
            changes: $pullRequest->changes,
            breaking: $pullRequest->breaking,
        );
    }
}
