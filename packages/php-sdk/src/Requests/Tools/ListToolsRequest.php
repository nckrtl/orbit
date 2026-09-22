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
        $data = $this->unwrapDataList($response, rejectMalformed: true);
        $requestId = $this->successRequestId($response);

        $tools = array_map(
            static fn (array $item): ToolResponse => ToolResponse::fromGatewayData($item, $requestId),
            $data,
        );

        return new ToolsResponse($tools, $requestId);
    }

    protected function defaultQuery(): array
    {
        return ['node_id' => $this->nodeId];
    }
}
