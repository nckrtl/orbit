<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Deployments;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Deployments\InstanceDeploymentResponse;
use Orbit\Sdk\Responses\Deployments\InstanceDeploymentsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListInstanceDeploymentsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly int $instanceId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/instances/{$this->instanceId}/deployments";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): InstanceDeploymentsResponse
    {
        $requestId = $this->successRequestId($response);
        $deployments = [];

        foreach ($this->unwrapDataList($response) as $deployment) {
            $deployments[] = InstanceDeploymentResponse::fromGatewayData($deployment, $requestId);
        }

        return new InstanceDeploymentsResponse($deployments, $requestId);
    }
}
