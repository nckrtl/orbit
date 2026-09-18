<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Metrics;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Metrics\FleetNodeMetricsResponse;
use Orbit\Sdk\Responses\Metrics\MetricsNodesResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListMetricsNodesRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/metrics/nodes';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): MetricsNodesResponse
    {
        $requestId = $this->successRequestId($response);
        $nodes = [];

        foreach ($this->unwrapDataList($response) as $data) {
            $nodes[] = FleetNodeMetricsResponse::fromGatewayData($data);
        }

        return new MetricsNodesResponse($nodes, $requestId);
    }
}
