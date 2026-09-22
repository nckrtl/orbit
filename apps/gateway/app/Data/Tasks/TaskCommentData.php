<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use App\Domain\Tasks\TaskCommentType;
use App\Models\TaskComment;
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
        public ?string $reviewerThreadId,
        public ?string $driverTurn,
        public ?string $commitSha,
        public ?string $prUrl,
    ) {}

    public static function fromModel(TaskComment $comment): self
    {
        return new self(
            id: $comment->id,
            taskGroupId: $comment->task_group_id,
            taskId: $comment->task_id,
            agentThreadId: $comment->agent_thread_id,
            type: TaskCommentType::tryFrom((string) $comment->getRawOriginal('type'))->value,
            body: $comment->body,
            author: $comment->author,
            postedAt: $comment->posted_at->toIso8601String(),
            reviewAttempt: $comment->review_attempt,
            reviewerThreadId: $comment->reviewer_thread_id,
            driverTurn: $comment->driver_turn,
            commitSha: $comment->commit_sha,
            prUrl: $comment->pr_url,
        );
    }
}
