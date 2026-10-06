<?php

declare(strict_types=1);

namespace App\Data\ProjectDocuments;

use App\Domain\Shared\ResourceOperationException;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

final readonly class DocumentBody
{
    public const int MAX_BYTES = 10485760;

    public const int INLINE_BYTES = 1048576;

    public const array EDITABLE_TYPES = ['text/plain', 'text/markdown', 'application/json', 'application/yaml', 'text/csv'];

    public string $sha256;

    public int $sizeBytes;

    public function __construct(#[SensitiveParameter] public string $bytes, public string $mediaType)
    {
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new ResourceOperationException('project_documents.content_too_large', 'Document content is too large.', 413);
        }
        if (strlen($mediaType) > 127 || preg_match('~^[a-z0-9!#$&^_.+\-]+/[a-z0-9!#$&^_.+\-]+$~D', $mediaType) !== 1) {
            throw ValidationException::withMessages(['media_type' => 'Use a lowercase media type without parameters.']);
        }
        $this->sha256 = hash('sha256', $bytes);
        $this->sizeBytes = strlen($bytes);
    }

    public static function text(#[SensitiveParameter] string $text, string $mediaType = 'text/plain'): self
    {
        $body = new self($text, $mediaType);
        $body->assertEditable();

        return $body;
    }

    public static function base64(#[SensitiveParameter] string $encoded, string $mediaType = 'application/octet-stream'): self
    {
        if (strlen($encoded) > 4 * (int) ceil(self::MAX_BYTES / 3)) {
            throw new ResourceOperationException('project_documents.content_too_large', 'Document content is too large.', 413);
        }
        $bytes = base64_decode($encoded, true);
        if ($bytes === false || base64_encode($bytes) !== $encoded) {
            throw ValidationException::withMessages(['content_base64' => 'Use canonical base64.']);
        }

        return new self($bytes, $mediaType);
    }

    public function assertEditable(): void
    {
        if ($this->sizeBytes > self::INLINE_BYTES || ! mb_check_encoding($this->bytes, 'UTF-8')
            || str_contains($this->bytes, "\0") || ! in_array($this->mediaType, self::EDITABLE_TYPES, true)) {
            throw new ResourceOperationException('project_documents.not_editable', 'Document cannot be edited inline.', 422);
        }
    }

    /** @return array<string, int|string> */
    public function __debugInfo(): array
    {
        return ['media_type' => $this->mediaType, 'size_bytes' => $this->sizeBytes, 'sha256' => $this->sha256];
    }
}
