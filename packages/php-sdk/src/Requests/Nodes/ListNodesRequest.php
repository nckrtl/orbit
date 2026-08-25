<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Nodes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Orbit\Sdk\Responses\Nodes\NodesResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListNodesRequest extends GatewayRequest
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/nodes';
    }

    public function createDtoFromResponse(Response $response): NodesResponse
    {
        $data = $this->unwrapDataList($response);
        $meta = $this->unwrapMeta($response);
        $requestId = is_string($meta['request_id'] ?? null) ? $meta['request_id'] : '';
        $nodes = [];

        foreach ($data as $node) {
            $nodes[] = NodeResponse::fromGatewayData($node, $requestId);
        }

        return new NodesResponse($nodes, $requestId);
    }
}
