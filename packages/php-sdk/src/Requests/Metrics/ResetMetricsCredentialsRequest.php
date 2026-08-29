<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Metrics;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Metrics\MetricsCredentialsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use SensitiveParameter;

final class ResetMetricsCredentialsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::POST;

    public function resolveEndpoint(): string
    {
        return '/api/v1/metrics/credentials/reset';
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): MetricsCredentialsResponse
    {
        return MetricsCredentialsResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }
}
