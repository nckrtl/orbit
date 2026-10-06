<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\ProjectDocuments;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\ProjectDocuments\ProjectDocumentStorageResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowProjectDocumentStorageRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/project-document-storage';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ProjectDocumentStorageResponse
    {
        $data = $this->unwrapData($response);

        return ProjectDocumentStorageResponse::fromGatewayData($data, $this->successRequestId($response));
    }
}
