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
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/nodes';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): NodesResponse
    {
        $data = $this->unwrapDataList($response);
        $requestId = $this->successRequestId($response);
        $nodes = [];

        foreach ($data as $node) {
            $nodes[] = NodeResponse::fromGatewayData($node, $requestId);
        }

        return new NodesResponse($nodes, $requestId);
    }
}
