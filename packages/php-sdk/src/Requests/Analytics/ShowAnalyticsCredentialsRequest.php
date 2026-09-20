<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Analytics;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Analytics\AnalyticsCredentialsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use SensitiveParameter;

/** Reads whether a Plausible Stats API key is stored. The key itself is never returned. */
final class ShowAnalyticsCredentialsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

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
