<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Deployments;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Deployments\AppInstanceDeploymentResponse;
use Orbit\Sdk\Responses\Deployments\AppInstanceDeploymentsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListAppInstanceDeploymentsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly int $appInstanceId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/instances/{$this->appInstanceId}/deployments";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): AppInstanceDeploymentsResponse
    {
        $requestId = $this->successRequestId($response);
        $deployments = [];

        foreach ($this->unwrapDataList($response) as $deployment) {
            $deployments[] = AppInstanceDeploymentResponse::fromGatewayData($deployment, $requestId);
        }

        return new AppInstanceDeploymentsResponse($deployments, $requestId);
    }
}
