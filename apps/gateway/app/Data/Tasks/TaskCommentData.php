<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use App\Domain\Tasks\TaskCommentType;
use App\Models\TaskComment;
use InvalidArgumentException;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class TaskCommentData extends Data
{
    public function __construct(
        public int $id,
        public int $taskGroupId,
        public int $taskId,
        public ?int $agentThreadId,
        public string $type,
        public string $body,
        public string $author,
        public string $postedAt,
        public ?int $reviewAttempt,
        public ?string $commitSha,
        public ?TaskCommentPullRequestData $pullRequest,
    ) {}

    public static function fromModel(TaskComment $comment): self
    {
        return new self(
            id: $comment->id,
            taskGroupId: $comment->task_group_id,
            taskId: $comment->task_id,
            agentThreadId: $comment->agent_thread_id,
            type: self::typeValue($comment),
            body: $comment->body,
            author: $comment->author,
            postedAt: $comment->posted_at->toIso8601String(),
            reviewAttempt: $comment->review_attempt,
            commitSha: $comment->commit_sha,
            pullRequest: TaskCommentPullRequestData::fromStored($comment->pull_request),
        );
    }

    private static function typeValue(TaskComment $comment): string
    {
        $type = TaskCommentType::tryFrom((string) $comment->getRawOriginal('type'));
        if (! $type instanceof TaskCommentType) {
            throw new InvalidArgumentException('Task comment type is invalid.');
        }

        return $type->value;
    }
}
