<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\ProjectDocuments;

final readonly class DownloadProjectDocumentResponse
{
    public function __construct(public int $entryId, public int $revision, public DocumentVersionResponse $version, #[\SensitiveParameter] public string $contentBase64, public string $requestId) {}

    /** @param array<array-key, mixed> $data */
    public static function fromGatewayData(#[\SensitiveParameter] array $data, string $requestId): self
    {
        return new self(is_int($data['entry_id'] ?? null) ? $data['entry_id'] : 0, is_int($data['revision'] ?? null) ? $data['revision'] : 0,
            DocumentVersionResponse::fromGatewayData(is_array($data['version'] ?? null) ? $data['version'] : []), is_string($data['content_base64'] ?? null) ? $data['content_base64'] : '', $requestId);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['data' => ['entry_id' => $this->entryId, 'revision' => $this->revision, 'version' => $this->version->toArray(), 'content_base64' => $this->contentBase64], 'meta' => ['request_id' => $this->requestId]];
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['class' => self::class];
    }

    public function decodedBytes(): string
    {
        if (strlen($this->contentBase64) > 13981016 || $this->version->sizeBytes < 0 || $this->version->sizeBytes > 10485760) {
            throw new \UnexpectedValueException('Invalid document body.');
        }
        $bytes = base64_decode($this->contentBase64, true);
        if ($bytes === false || base64_encode($bytes) !== $this->contentBase64 || strlen($bytes) !== $this->version->sizeBytes
            || ! hash_equals($this->version->sha256, hash('sha256', $bytes))) {
            throw new \UnexpectedValueException('Document checksum or size does not match.');
        }

        return $bytes;
    }
}
