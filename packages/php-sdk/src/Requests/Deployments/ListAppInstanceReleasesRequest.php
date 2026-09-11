<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Deployments;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Deployments\DeploymentReleasesResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListAppInstanceReleasesRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(private readonly int $appInstanceId) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/instances/{$this->appInstanceId}/releases";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): DeploymentReleasesResponse
    {
        return DeploymentReleasesResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }
}
