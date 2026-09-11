<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Deployments;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Deployments\DeploymentConfigResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowAppInstanceDeploymentConfigRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(private readonly int $appInstanceId) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/instances/{$this->appInstanceId}/deployment-config";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): DeploymentConfigResponse
    {
        return DeploymentConfigResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }
}
