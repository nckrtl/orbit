<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Projects;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Projects\DevelopmentNodeExclusionResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class AddProjectExcludedNodeRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $projectId,
        private readonly int $nodeId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/projects/{$this->projectId}/excluded-nodes/{$this->nodeId}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): DevelopmentNodeExclusionResponse
    {
        return DevelopmentNodeExclusionResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }
}
