<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Routes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Routes\RouteResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ClearRouteTargetRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly int $routeId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/routes/{$this->routeId}/target";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): RouteResponse
    {
        return RouteResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
