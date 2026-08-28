<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tools;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Tools\ToolResponse;
use Orbit\Sdk\Responses\Tools\ToolsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListToolsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly int $nodeId,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/tools';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ToolsResponse
    {
        $requestId = $this->successRequestId($response);
        $tools = [];

        foreach ($this->unwrapDataList($response) as $data) {
            $tools[] = ToolResponse::fromGatewayData($data, $requestId);
        }

        return new ToolsResponse($tools, $requestId);
    }

    /** @return array{node_id: int} */
    protected function defaultQuery(): array
    {
        return ['node_id' => $this->nodeId];
    }
}
