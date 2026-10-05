<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\ProjectDocuments;

final readonly class DocumentVersionResponse
{
    public function __construct(
        public int $id,
        public int $number,
        public string $mediaType,
        public int $sizeBytes,
        public string $sha256,
        public string $createdAt,
        public ?int $createdByNodeId,
    ) {}

    /** @param array<array-key, mixed> $data */
    public static function fromGatewayData(#[\SensitiveParameter] array $data): self
    {
        return new self(
            id: is_int($data['id'] ?? null) ? $data['id'] : 0,
            number: is_int($data['number'] ?? null) ? $data['number'] : 0,
            mediaType: is_string($data['media_type'] ?? null) ? $data['media_type'] : '',
            sizeBytes: is_int($data['size_bytes'] ?? null) ? $data['size_bytes'] : 0,
            sha256: is_string($data['sha256'] ?? null) ? $data['sha256'] : '',
            createdAt: is_string($data['created_at'] ?? null) ? $data['created_at'] : '',
            createdByNodeId: is_int($data['created_by_node_id'] ?? null) ? $data['created_by_node_id'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'media_type' => $this->mediaType,
            'size_bytes' => $this->sizeBytes,
            'sha256' => $this->sha256,
            'created_at' => $this->createdAt,
            'created_by_node_id' => $this->createdByNodeId,
        ];
    }
}
