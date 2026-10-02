<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tasks;

/** One question an implementer or reviewer asked on a subtask. */
final readonly class TaskQuestionResponse
{
    public function __construct(
        public int $id,
        public int $taskId,
        public int $subtaskId,
        public int $attempt,
        public string $askedBy,
        public string $question,
        public string $status,
        public ?string $answeredBy,
        public ?string $answer,
        public ?string $cause,
        public string $askedAt,
        public ?string $escalatedAt,
        public ?string $answeredAt,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(array $data, string $requestId): self
    {
        return new self(
            id: TaskFields::id($data, 'id', 'task question', $requestId),
            taskId: TaskFields::id($data, 'task_id', 'task question', $requestId),
            subtaskId: TaskFields::id($data, 'subtask_id', 'task question', $requestId),
            attempt: TaskFields::nonNegative($data, 'attempt', 'task question', $requestId),
            askedBy: TaskFields::text($data, 'asked_by', 'task question', $requestId),
            question: TaskFields::text($data, 'question', 'task question', $requestId),
            status: TaskFields::text($data, 'status', 'task question', $requestId),
            answeredBy: TaskFields::nullableText($data, 'answered_by'),
            answer: TaskFields::nullableText($data, 'answer'),
            cause: TaskFields::nullableText($data, 'cause'),
            askedAt: TaskFields::text($data, 'asked_at', 'task question', $requestId),
            escalatedAt: TaskFields::nullableText($data, 'escalated_at'),
            answeredAt: TaskFields::nullableText($data, 'answered_at'),
            requestId: $requestId,
        );
    }

    /**
     * The task and subtask reference, such as #13/57, when the list has no Project code.
     */
    public function reference(): string
    {
        return "#{$this->taskId}/{$this->subtaskId}";
    }

    /**
     * @return array{
     *     id: int,
     *     task_id: int,
     *     subtask_id: int,
     *     attempt: int,
     *     asked_by: string,
     *     question: string,
     *     status: string,
     *     answered_by: string|null,
     *     answer: string|null,
     *     cause: string|null,
     *     asked_at: string,
     *     escalated_at: string|null,
     *     answered_at: string|null,
     *     request_id: string
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'task_id' => $this->taskId,
            'subtask_id' => $this->subtaskId,
            'attempt' => $this->attempt,
            'asked_by' => $this->askedBy,
            'question' => $this->question,
            'status' => $this->status,
            'answered_by' => $this->answeredBy,
            'answer' => $this->answer,
            'cause' => $this->cause,
            'asked_at' => $this->askedAt,
            'escalated_at' => $this->escalatedAt,
            'answered_at' => $this->answeredAt,
            'request_id' => $this->requestId,
        ];
    }
}
