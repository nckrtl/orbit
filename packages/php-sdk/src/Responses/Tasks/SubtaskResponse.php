<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tasks;

/** One ordered subtask of a task group; the Gateway calls it a Task. */
final readonly class SubtaskResponse
{
    public function __construct(
        public int $id,
        public int $taskGroupId,
        public int $position,
        public string $title,
        public string $brief,
        /** @var list<array<string, string|bool>> */
        public array $deliverables,
        public string $status,
        public ?string $type,
        public ?int $implementerAgentThreadId,
        public ?string $targetThreadId,
        public ?string $completionSummary,
        public bool $assistanceRequested,
        public ?string $assistanceReason,
        public ?int $tokens,
        public ?int $lineDiff,
        public ?int $linesAdded,
        public ?int $linesDeleted,
        public ?int $durationMs,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(array $data, string $requestId): self
    {
        return new self(
            id: TaskFields::id($data, 'id', 'subtask', $requestId),
            taskGroupId: TaskFields::id($data, 'task_group_id', 'subtask', $requestId),
            position: TaskFields::id($data, 'position', 'subtask', $requestId),
            title: TaskFields::text($data, 'title', 'subtask', $requestId),
            brief: TaskFields::text($data, 'brief', 'subtask', $requestId),
            deliverables: TaskFields::deliverables($data, 'subtask', $requestId),
            status: TaskFields::text($data, 'status', 'subtask', $requestId),
            type: TaskFields::nullableText($data, 'type'),
            implementerAgentThreadId: TaskFields::nullableInt($data, 'implementer_agent_thread_id'),
            targetThreadId: TaskFields::nullableText($data, 'target_thread_id'),
            completionSummary: TaskFields::nullableText($data, 'completion_summary'),
            assistanceRequested: ($data['assistance_requested'] ?? false) === true,
            assistanceReason: TaskFields::nullableText($data, 'assistance_reason'),
            tokens: TaskFields::nullableInt($data, 'tokens'),
            lineDiff: TaskFields::nullableInt($data, 'line_diff'),
            linesAdded: TaskFields::nullableInt($data, 'lines_added'),
            linesDeleted: TaskFields::nullableInt($data, 'lines_deleted'),
            durationMs: TaskFields::nullableInt($data, 'duration_ms'),
            requestId: $requestId,
        );
    }

    /**
     * @return array{
     *     id: int,
     *     task_group_id: int,
     *     position: int,
     *     title: string,
     *     brief: string,
     *     deliverables: list<array<string, string|bool>>,
     *     status: string,
     *     type: string|null,
     *     implementer_agent_thread_id: int|null,
     *     target_thread_id: string|null,
     *     completion_summary: string|null,
     *     assistance_requested: bool,
     *     assistance_reason: string|null,
     *     tokens: int|null,
     *     line_diff: int|null,
     *     lines_added: int|null,
     *     lines_deleted: int|null,
     *     duration_ms: int|null,
     *     request_id: string
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'task_group_id' => $this->taskGroupId,
            'position' => $this->position,
            'title' => $this->title,
            'brief' => $this->brief,
            'deliverables' => $this->deliverables,
            'status' => $this->status,
            'type' => $this->type,
            'implementer_agent_thread_id' => $this->implementerAgentThreadId,
            'target_thread_id' => $this->targetThreadId,
            'completion_summary' => $this->completionSummary,
            'assistance_requested' => $this->assistanceRequested,
            'assistance_reason' => $this->assistanceReason,
            'tokens' => $this->tokens,
            'line_diff' => $this->lineDiff,
            'lines_added' => $this->linesAdded,
            'lines_deleted' => $this->linesDeleted,
            'duration_ms' => $this->durationMs,
            'request_id' => $this->requestId,
        ];
    }
}
