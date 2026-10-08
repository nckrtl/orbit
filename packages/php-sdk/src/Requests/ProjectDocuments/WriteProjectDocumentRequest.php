<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\ProjectDocuments;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\ProjectDocuments\ProjectDocumentResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class WriteProjectDocumentRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::PUT;

    public function __construct(
        private readonly int $projectId,
        private readonly int $entryId,
        private readonly int $expectedRevision,
        #[\SensitiveParameter] private readonly ?string $contentText = null,
        #[\SensitiveParameter] private readonly ?string $contentBase64 = null,
        private readonly ?string $mediaType = null
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/projects/{$this->projectId}/documents/{$this->entryId}/content";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ProjectDocumentResponse
    {
        $data = $this->unwrapData($response);

        return ProjectDocumentResponse::fromGatewayData($data, $this->successRequestId($response));
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        return array_filter([
            'expected_revision' => $this->expectedRevision,
            'content_text' => $this->contentText,
            'content_base64' => $this->contentBase64,
            'media_type' => $this->mediaType,
        ], static fn (mixed $value): bool => $value !== null);
    }

    public static function encodeBytes(#[\SensitiveParameter] string $bytes): string
    {
        if (strlen($bytes) > 10485760) {
            throw new \InvalidArgumentException('Document content is too large.');
        }

        return base64_encode($bytes);
    }
}
