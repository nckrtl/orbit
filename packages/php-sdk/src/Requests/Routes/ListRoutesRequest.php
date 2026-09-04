<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Routes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Routes\RouteResponse;
use Orbit\Sdk\Responses\Routes\RoutesResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListRoutesRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/routes';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): RoutesResponse
    {
        $requestId = $this->successRequestId($response);
        $routes = array_map(
            static fn (array $data): RouteResponse => RouteResponse::fromGatewayData($data, $requestId),
            $this->unwrapDataList($response),
        );

        return new RoutesResponse($routes, $requestId);
    }
}
