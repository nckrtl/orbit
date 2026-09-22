<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tools;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Tools\ToolManagerResponse;
use Orbit\Sdk\Responses\Tools\ToolManagersResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListToolManagersRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly int $nodeId,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/tool-managers';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ToolManagersResponse
    {
        $data = $this->unwrapDataList($response, rejectMalformed: true);
        $requestId = $this->successRequestId($response);

        $managers = array_map(
            static fn (array $item): ToolManagerResponse => ToolManagerResponse::fromGatewayData(
                $item,
                $requestId,
            ),
            $data,
        );

        return new ToolManagersResponse($managers, $requestId);
    }

    protected function defaultQuery(): array
    {
        return ['node_id' => $this->nodeId];
    }
}
