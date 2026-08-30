<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Metrics;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Metrics\MetricsStatusResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use SensitiveParameter;

final class ShowMetricsStatusRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/metrics/status';
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): MetricsStatusResponse
    {
        return MetricsStatusResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
