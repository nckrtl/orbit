<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\ProjectDocuments;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\ProjectDocuments\ProjectDocumentResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowProjectDocumentRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly int $projectId,
        private readonly int $entryId
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/projects/{$this->projectId}/documents/{$this->entryId}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ProjectDocumentResponse
    {
        $data = $this->unwrapData($response);

        return ProjectDocumentResponse::fromGatewayData($data, $this->successRequestId($response));
    }
}
