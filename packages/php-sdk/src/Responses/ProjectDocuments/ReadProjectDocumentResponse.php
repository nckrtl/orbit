<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\ProjectDocuments;

final readonly class ReadProjectDocumentResponse
{
    public function __construct(public int $entryId, public int $revision, public DocumentVersionResponse $version, #[\SensitiveParameter] public string $contentText, public string $requestId) {}

    /** @param array<array-key, mixed> $data */
    public static function fromGatewayData(#[\SensitiveParameter] array $data, string $requestId): self
    {
        return new self(is_int($data['entry_id'] ?? null) ? $data['entry_id'] : 0, is_int($data['revision'] ?? null) ? $data['revision'] : 0,
            DocumentVersionResponse::fromGatewayData(is_array($data['version'] ?? null) ? $data['version'] : []), is_string($data['content_text'] ?? null) ? $data['content_text'] : '', $requestId);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['data' => ['entry_id' => $this->entryId, 'revision' => $this->revision, 'version' => $this->version->toArray(), 'content_text' => $this->contentText], 'meta' => ['request_id' => $this->requestId]];
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['class' => self::class];
    }
}
