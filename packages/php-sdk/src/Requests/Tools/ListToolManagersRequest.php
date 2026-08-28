<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tools;

use Orbit\Sdk\GatewayApiException;
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

    /** @mago-expect analysis:mixed-assignment Gateway collection members remain mixed until validated. */
    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ToolManagersResponse
    {
        $data = $response->json('data');
        if (! is_array($data) || ! array_is_list($data)) {
            throw new GatewayApiException('Gateway tool manager list contains invalid data.');
        }

        foreach ($data as $item) {
            if (
                ! is_array($item)
                || ! array_all(array_keys($item), static fn (int|string $key): bool => is_string($key))
            ) {
                throw new GatewayApiException('Gateway tool manager list contains invalid data.');
            }
        }

        $requestId = $this->successRequestId($response);

        /** @var list<array<string, mixed>> $data */
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
