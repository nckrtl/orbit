<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\ProjectDocuments;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\ProjectDocuments\DownloadProjectDocumentResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class DownloadProjectDocumentRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly int $projectId,
        private readonly int $entryId,
        private readonly ?int $version = null
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/projects/{$this->projectId}/documents/{$this->entryId}/download";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): DownloadProjectDocumentResponse
    {
        $data = $this->unwrapData($response);

        return DownloadProjectDocumentResponse::fromGatewayData($data, $this->successRequestId($response));
    }

    /** @return array<string, mixed> */
    protected function defaultQuery(): array
    {
        return array_filter([
            'version' => $this->version,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
