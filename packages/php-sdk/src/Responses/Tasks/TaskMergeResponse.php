<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tasks;

/** One open review-and-merge task in the `tasks:status` view. */
final readonly class TaskMergeResponse
{
    public function __construct(
        public int $id,
        public int $projectId,
        public ?string $project,
        public ?string $projectCode,
        public string $title,
        public string $status,
        public ?string $prUrl,
        public ?string $prBranch,
        public ?string $mergeStatus,
        public ?string $mergeReason,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(array $data, string $requestId): self
    {
        return new self(
            id: TaskFields::id($data, 'id', 'review-and-merge task', $requestId),
            projectId: TaskFields::id($data, 'project_id', 'review-and-merge task', $requestId),
            project: TaskFields::nullableText($data, 'project'),
            projectCode: TaskFields::nullableText($data, 'project_code'),
            title: TaskFields::text($data, 'title', 'review-and-merge task', $requestId),
            status: TaskFields::text($data, 'status', 'review-and-merge task', $requestId),
            prUrl: TaskFields::nullableText($data, 'pr_url'),
            prBranch: TaskFields::nullableText($data, 'pr_branch'),
            mergeStatus: TaskFields::nullableText($data, 'merge_status'),
            mergeReason: TaskFields::nullableText($data, 'merge_reason'),
        );
    }

    /** The task's human reference, such as ORB-13, or #13 when the Project has no code. */
    public function reference(): string
    {
        return $this->projectCode === null || $this->projectCode === ''
            ? "#{$this->id}"
            : "{$this->projectCode}-{$this->id}";
    }

    /**
     * @return array{id: int, project_id: int, project: string|null, project_code: string|null, title: string, status: string,
     *     pr_url: string|null, pr_branch: string|null, merge_status: string|null, merge_reason: string|null}
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
            'pr_url' => $this->prUrl,
            'pr_branch' => $this->prBranch,
            'merge_status' => $this->mergeStatus,
            'merge_reason' => $this->mergeReason,
        ];
    }
}
