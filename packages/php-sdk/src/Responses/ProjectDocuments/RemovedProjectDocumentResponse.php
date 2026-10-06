<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\ProjectDocuments;

final readonly class RemovedProjectDocumentResponse
{
    public function __construct(public int $id, public bool $removed, public bool $cleanupPending, public string $requestId) {}

    /** @param array<array-key, mixed> $data */
    public static function fromGatewayData(array $data, string $requestId): self
    {
        return new self(is_int($data['id'] ?? null) ? $data['id'] : 0, ($data['removed'] ?? false) === true, ($data['cleanup_pending'] ?? false) === true, $requestId);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['data' => ['id' => $this->id, 'removed' => $this->removed, 'cleanup_pending' => $this->cleanupPending], 'meta' => ['request_id' => $this->requestId]];
    }
}
