<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\GatewayReleases;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\GatewayReleases\GatewayReleaseAutomationResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

/** Shows the state of automatic Gateway releases. */
final class ShowGatewayReleaseAutomationRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/gateway/release-automation';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): GatewayReleaseAutomationResponse
    {
        return GatewayReleaseAutomationResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
