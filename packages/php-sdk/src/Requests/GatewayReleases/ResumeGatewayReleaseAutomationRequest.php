<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\GatewayReleases;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\GatewayReleases\GatewayReleaseAutomationResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

/** Clears a pause of automatic Gateway releases after the operator decided what to do. */
final class ResumeGatewayReleaseAutomationRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::POST;

    public function resolveEndpoint(): string
    {
        return '/api/v1/gateway/release-automation/resume';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): GatewayReleaseAutomationResponse
    {
        return GatewayReleaseAutomationResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
