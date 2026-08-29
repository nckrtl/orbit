<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Metrics;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Metrics\MetricsCredentialsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use SensitiveParameter;

final class ShowMetricsCredentialsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/metrics/credentials';
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): MetricsCredentialsResponse
    {
        return MetricsCredentialsResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }
}
