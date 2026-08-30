<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Metrics;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Metrics\MetricsMutationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;
use SensitiveParameter;

final class DisableMetricsRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly bool $force = false,
        private readonly bool $purgeData = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/metrics';
    }

    protected function defaultBody(): array
    {
        return ['force' => $this->force, 'purge_data' => $this->purgeData];
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): MetricsMutationResponse
    {
        return MetricsMutationResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }
}
