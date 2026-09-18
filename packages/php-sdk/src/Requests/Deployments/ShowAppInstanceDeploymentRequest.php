<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Deployments;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Deployments\AppInstanceDeploymentResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowAppInstanceDeploymentRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly int $deploymentId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/deployments/{$this->deploymentId}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): AppInstanceDeploymentResponse
    {
        return AppInstanceDeploymentResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
