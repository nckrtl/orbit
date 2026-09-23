<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Nodes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Projects\DevelopmentNodeExclusionResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class RemoveNodeExcludedProjectRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly int $nodeId,
        private readonly int $projectId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/nodes/{$this->nodeId}/excluded-projects/{$this->projectId}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): DevelopmentNodeExclusionResponse
    {
        return DevelopmentNodeExclusionResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }
}
