<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tasks;

final readonly class TaskGroupResponse
{
    /** A task group holds at most this many subtasks in one response. */
    private const int MAX_SUBTASKS = 1_000;

    /** @param list<SubtaskResponse> $tasks */
    public function __construct(
        public int $id,
        public int $projectId,
        public ?string $project,
        public ?string $projectCode,
        public string $title,
        public string $brief,
        public string $status,
        public ?string $taskableType,
        public ?int $taskableId,
        public ?int $reviewerAgentThreadId,
        public ?string $prUrl,
        public bool $notifyCoder,
        public bool $assistanceRequested,
        public ?string $assistanceReason,
        public ?string $implementerModel,
        public ?string $reviewerModel,
        public ?string $executionMode,
        public ?int $tokens,
        public ?int $lineDiff,
        public ?int $linesAdded,
        public ?int $linesDeleted,
        public ?int $durationMs,
        public array $tasks,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(array $data, string $requestId): self
    {
        return new self(
            id: TaskFields::id($data, 'id', 'task group', $requestId),
            projectId: TaskFields::id($data, 'project_id', 'task group', $requestId),
            project: TaskFields::nullableText($data, 'project'),
            projectCode: TaskFields::nullableText($data, 'project_code'),
            title: TaskFields::text($data, 'title', 'task group', $requestId),
            brief: TaskFields::text($data, 'brief', 'task group', $requestId),
            status: TaskFields::text($data, 'status', 'task group', $requestId),
            taskableType: TaskFields::nullableText($data, 'taskable_type'),
            taskableId: TaskFields::nullableInt($data, 'taskable_id'),
            reviewerAgentThreadId: TaskFields::nullableInt($data, 'reviewer_agent_thread_id'),
            prUrl: TaskFields::nullableText($data, 'pr_url'),
            notifyCoder: ($data['notify_coder'] ?? false) === true,
            assistanceRequested: ($data['assistance_requested'] ?? false) === true,
            assistanceReason: TaskFields::nullableText($data, 'assistance_reason'),
            implementerModel: TaskFields::nullableText($data, 'implementer_model'),
            reviewerModel: TaskFields::nullableText($data, 'reviewer_model'),
            executionMode: TaskFields::nullableText($data, 'execution_mode'),
            tokens: TaskFields::nullableInt($data, 'tokens'),
            lineDiff: TaskFields::nullableInt($data, 'line_diff'),
            linesAdded: TaskFields::nullableInt($data, 'lines_added'),
            linesDeleted: TaskFields::nullableInt($data, 'lines_deleted'),
            durationMs: TaskFields::nullableInt($data, 'duration_ms'),
            tasks: self::subtasks($data['tasks'] ?? [], $requestId),
            requestId: $requestId,
        );
    }

    /**
     * The group's human reference, such as ORB-13, or #13 when the Project has no code.
     */
    public function reference(): string
    {
        return $this->projectCode === null || $this->projectCode === ''
            ? "#{$this->id}"
            : "{$this->projectCode}-{$this->id}";
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->projectId,
            'project' => $this->project,
            'project_code' => $this->projectCode,
            'title' => $this->title,
            'brief' => $this->brief,
            'status' => $this->status,
            'taskable_type' => $this->taskableType,
            'taskable_id' => $this->taskableId,
            'reviewer_agent_thread_id' => $this->reviewerAgentThreadId,
            'pr_url' => $this->prUrl,
            'notify_coder' => $this->notifyCoder,
            'assistance_requested' => $this->assistanceRequested,
            'assistance_reason' => $this->assistanceReason,
            'implementer_model' => $this->implementerModel,
            'reviewer_model' => $this->reviewerModel,
            'execution_mode' => $this->executionMode,
            'tokens' => $this->tokens,
            'line_diff' => $this->lineDiff,
            'lines_added' => $this->linesAdded,
            'lines_deleted' => $this->linesDeleted,
            'duration_ms' => $this->durationMs,
            'tasks' => array_map(static function (SubtaskResponse $task): array {
                $data = $task->toArray();
                unset($data['request_id']);

                return $data;
            }, $this->tasks),
            'request_id' => $this->requestId,
        ];
    }

    /** @return list<SubtaskResponse> */
    private static function subtasks(mixed $value, string $requestId): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > self::MAX_SUBTASKS) {
            throw TaskFields::invalid('task group', $requestId);
        }

        $tasks = [];

        foreach ($value as $task) {
            if (! is_array($task)) {
                throw TaskFields::invalid('task group', $requestId);
            }

            $data = [];
            foreach ($task as $key => $item) {
                if (! is_string($key)) {
                    throw TaskFields::invalid('task group', $requestId);
                }
                $data[$key] = $item;
            }

            $tasks[] = SubtaskResponse::fromGatewayData($data, $requestId);
        }

        return $tasks;
    }
}
