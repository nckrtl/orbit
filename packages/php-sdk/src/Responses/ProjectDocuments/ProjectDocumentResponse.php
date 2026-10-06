<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\ProjectDocuments;

final readonly class ProjectDocumentResponse
{
    public function __construct(
        public int $id,
        public int $projectId,
        public string $kind,
        public ?int $parentId,
        public string $name,
        public int $revision,
        public ?string $archivedAt,
        public string $createdAt,
        public string $updatedAt,
        public string $path,
        public bool $isArchived,
        public ?DocumentVersionResponse $currentVersion, public string $requestId,
    ) {}

    /** @param array<array-key, mixed> $data */
    public static function fromGatewayData(#[\SensitiveParameter] array $data, string $requestId): self
    {
        return new self(
            id: is_int($data['id'] ?? null) ? $data['id'] : 0,
            projectId: is_int($data['project_id'] ?? null) ? $data['project_id'] : 0,
            kind: is_string($data['kind'] ?? null) ? $data['kind'] : '',
            parentId: is_int($data['parent_id'] ?? null) ? $data['parent_id'] : null,
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            revision: is_int($data['revision'] ?? null) ? $data['revision'] : 0,
            archivedAt: is_string($data['archived_at'] ?? null) ? $data['archived_at'] : null,
            createdAt: is_string($data['created_at'] ?? null) ? $data['created_at'] : '',
            updatedAt: is_string($data['updated_at'] ?? null) ? $data['updated_at'] : '',
            path: is_string($data['path'] ?? null) ? $data['path'] : '',
            isArchived: is_bool($data['is_archived'] ?? null) ? $data['is_archived'] : false,
            currentVersion: is_array($data['current_version'] ?? null) ? DocumentVersionResponse::fromGatewayData($data['current_version']) : null,
            requestId: $requestId,
        );
    }

    /** @return array<string, mixed> */
    public function dataArray(): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->projectId,
            'kind' => $this->kind,
            'parent_id' => $this->parentId,
            'name' => $this->name,
            'revision' => $this->revision,
            'archived_at' => $this->archivedAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'path' => $this->path,
            'is_archived' => $this->isArchived,
            'current_version' => $this->currentVersion?->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['data' => $this->dataArray(), 'meta' => ['request_id' => $this->requestId]];
    }
}
