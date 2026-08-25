<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Nodes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowNodeRequest extends GatewayRequest
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly int $nodeId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/nodes/{$this->nodeId}";
    }

    public function createDtoFromResponse(Response $response): NodeResponse
    {
        $data = $this->unwrapData($response);
        $meta = $this->unwrapMeta($response);
        $requestId = is_string($meta['request_id'] ?? null) ? $meta['request_id'] : '';

        return NodeResponse::fromGatewayData($data, $requestId);
    }
}
