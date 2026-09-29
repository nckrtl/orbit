<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Projects;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Projects\ProjectResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class DestroyProjectRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly int $projectId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/projects/{$this->projectId}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ProjectResponse
    {
        $data = $this->unwrapData($response);
        $requestId = $this->successRequestId($response);

        return ProjectResponse::fromGatewayData($data, $requestId);
    }
}
