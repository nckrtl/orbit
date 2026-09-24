<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tasks;

final readonly class TaskCommentResponse
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
        /** @var array{summary: string, changes: list<string>, breaking: list<string>}|null */
        public ?array $pullRequest,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(array $data, string $requestId): self
    {
        return new self(
            id: TaskFields::id($data, 'id', 'task comment', $requestId),
            taskGroupId: TaskFields::id($data, 'task_group_id', 'task comment', $requestId),
            taskId: TaskFields::id($data, 'task_id', 'task comment', $requestId),
            agentThreadId: TaskFields::nullableInt($data, 'agent_thread_id'),
            type: TaskFields::text($data, 'type', 'task comment', $requestId),
            body: TaskFields::text($data, 'body', 'task comment', $requestId),
            author: TaskFields::text($data, 'author', 'task comment', $requestId),
            postedAt: TaskFields::text($data, 'posted_at', 'task comment', $requestId),
            reviewAttempt: TaskFields::nullableInt($data, 'review_attempt'),
            commitSha: TaskFields::nullableText($data, 'commit_sha'),
            pullRequest: self::pullRequest($data['pull_request'] ?? null),
            requestId: $requestId,
        );
    }

    /**
     * @return array{
     *     id: int,
     *     task_group_id: int,
     *     task_id: int,
     *     agent_thread_id: int|null,
     *     type: string,
     *     body: string,
     *     author: string,
     *     posted_at: string,
     *     review_attempt: int|null,
     *     commit_sha: string|null,
     *     pull_request: array{summary: string, changes: list<string>, breaking: list<string>}|null,
     *     request_id: string
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'task_group_id' => $this->taskGroupId,
            'task_id' => $this->taskId,
            'agent_thread_id' => $this->agentThreadId,
            'type' => $this->type,
            'body' => $this->body,
            'author' => $this->author,
            'posted_at' => $this->postedAt,
            'review_attempt' => $this->reviewAttempt,
            'commit_sha' => $this->commitSha,
            'pull_request' => $this->pullRequest,
            'request_id' => $this->requestId,
        ];
    }

    /** @return array{summary: string, changes: list<string>, breaking: list<string>}|null */
    private static function pullRequest(mixed $value): ?array
    {
        if (! is_array($value) || ! is_string($value['summary'] ?? null)) {
            return null;
        }
        $lines = static fn (mixed $items): array => is_array($items)
            ? array_values(array_filter($items, is_string(...)))
            : [];

        return ['summary' => $value['summary'], 'changes' => $lines($value['changes'] ?? null), 'breaking' => $lines($value['breaking'] ?? null)];
    }
}
