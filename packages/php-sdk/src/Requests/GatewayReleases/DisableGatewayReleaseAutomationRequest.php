<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\GatewayReleases;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\GatewayReleases\GatewayReleaseAutomationResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

/** Turns automatic Gateway releases off. */
final class DisableGatewayReleaseAutomationRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::POST;

    public function resolveEndpoint(): string
    {
        return '/api/v1/gateway/release-automation/disable';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): GatewayReleaseAutomationResponse
    {
        return GatewayReleaseAutomationResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
