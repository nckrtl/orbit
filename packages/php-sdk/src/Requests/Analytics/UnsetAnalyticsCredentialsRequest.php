<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Analytics;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Analytics\AnalyticsCredentialsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use SensitiveParameter;

/** Clears the stored Plausible Stats API key. */
final class UnsetAnalyticsCredentialsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::DELETE;

    public function resolveEndpoint(): string
    {
        return '/api/v1/analytics/credentials';
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): AnalyticsCredentialsResponse
    {
        return AnalyticsCredentialsResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }
}
