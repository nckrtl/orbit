<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tasks;

/** One persisted agent thread of a task group; the Gateway calls it an AgentThread. */
final readonly class TaskAgentResponse
{
    public function __construct(
        public int $id,
        public int $taskGroupId,
        public ?int $taskId,
        public ?int $nodeId,
        public string $role,
        public ?string $model,
        public ?string $effort,
        public string $driver,
        public string $externalId,
        public ?string $state,
        public ?string $observedAt,
        public ?string $observationError,
        public ?string $error,
        public ?int $tokens,
        public ?int $linesAdded,
        public ?int $linesDeleted,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(array $data, string $requestId): self
    {
        return new self(
            id: TaskFields::id($data, 'id', 'agent thread', $requestId),
            taskGroupId: TaskFields::id($data, 'task_group_id', 'agent thread', $requestId),
            taskId: TaskFields::nullableInt($data, 'task_id'),
            nodeId: TaskFields::nullableInt($data, 'node_id'),
            role: TaskFields::text($data, 'role', 'agent thread', $requestId),
            model: TaskFields::nullableText($data, 'model'),
            effort: TaskFields::nullableText($data, 'effort'),
            driver: TaskFields::text($data, 'driver', 'agent thread', $requestId),
            externalId: TaskFields::text($data, 'external_id', 'agent thread', $requestId),
            state: TaskFields::nullableText($data, 'state'),
            observedAt: TaskFields::nullableText($data, 'observed_at'),
            observationError: TaskFields::nullableText($data, 'observation_error'),
            error: TaskFields::nullableText($data, 'error'),
            tokens: TaskFields::nullableInt($data, 'tokens'),
            linesAdded: TaskFields::nullableInt($data, 'lines_added'),
            linesDeleted: TaskFields::nullableInt($data, 'lines_deleted'),
            requestId: $requestId,
        );
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'task_group_id' => $this->taskGroupId,
            'task_id' => $this->taskId,
            'node_id' => $this->nodeId,
            'role' => $this->role,
            'model' => $this->model,
            'effort' => $this->effort,
            'driver' => $this->driver,
            'external_id' => $this->externalId,
            'state' => $this->state,
            'observed_at' => $this->observedAt,
            'observation_error' => $this->observationError,
            'error' => $this->error,
            'tokens' => $this->tokens,
            'lines_added' => $this->linesAdded,
            'lines_deleted' => $this->linesDeleted,
            'request_id' => $this->requestId,
        ];
    }
}
