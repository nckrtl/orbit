<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\ProjectDocuments;

final readonly class ProjectDocumentStorageResponse
{
    public function __construct(public ProjectDocumentStorageData $storage, public string $requestId) {}

    /** @param array<array-key, mixed> $data */
    public static function fromGatewayData(array $data, string $requestId): self
    {
        return new self(ProjectDocumentStorageData::fromGatewayData($data), $requestId);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['data' => $this->storage->toArray(), 'meta' => ['request_id' => $this->requestId]];
    }
}
