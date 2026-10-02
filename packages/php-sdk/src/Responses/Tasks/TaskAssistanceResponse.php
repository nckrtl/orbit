<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tasks;

final readonly class TaskAssistanceResponse
{
    public function __construct(
        public int $id,
        public int $projectId,
        public ?string $project,
        public ?string $projectCode,
        public string $title,
        public string $status,
        public ?string $assistanceKind,
        public ?string $assistanceQuestion,
        public ?string $assistanceReason,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(array $data, string $requestId): self
    {
        return new self(
            id: TaskFields::id($data, 'id', 'assisted task group', $requestId),
            projectId: TaskFields::id($data, 'project_id', 'assisted task group', $requestId),
            project: TaskFields::nullableText($data, 'project'),
            projectCode: TaskFields::nullableText($data, 'project_code'),
            title: TaskFields::text($data, 'title', 'assisted task group', $requestId),
            status: TaskFields::text($data, 'status', 'assisted task group', $requestId),
            assistanceKind: TaskFields::nullableText($data, 'assistance_kind'),
            assistanceQuestion: TaskFields::nullableText($data, 'assistance_question'),
            assistanceReason: TaskFields::nullableText($data, 'assistance_reason'),
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

    /**
     * @return array{
     *     id: int,
     *     project_id: int,
     *     project: string|null,
     *     project_code: string|null,
     *     title: string,
     *     status: string,
     *     assistance_kind: string|null,
     *     assistance_question: string|null,
     *     assistance_reason: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->projectId,
            'project' => $this->project,
            'project_code' => $this->projectCode,
            'title' => $this->title,
            'status' => $this->status,
            'assistance_kind' => $this->assistanceKind,
            'assistance_question' => $this->assistanceQuestion,
            'assistance_reason' => $this->assistanceReason,
        ];
    }
}
