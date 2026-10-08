<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Routes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Routes\RemovedRouteResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class DestroyRouteRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly int $routeId,
        private readonly ?bool $offline = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/routes/{$this->routeId}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): RemovedRouteResponse
    {
        return RemovedRouteResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    /** @return array{offline?: bool} */
    protected function defaultBody(): array
    {
        return $this->offline === null ? [] : ['offline' => $this->offline];
    }
}
