<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Nodes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Nodes\NodeMetricsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowNodeMetricsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly int $nodeId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/nodes/{$this->nodeId}/metrics";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): NodeMetricsResponse
    {
        return NodeMetricsResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
