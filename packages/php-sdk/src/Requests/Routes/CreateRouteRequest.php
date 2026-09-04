<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Routes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Routes\RouteResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class CreateRouteRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    /** @mago-expect lint:excessive-parameter-list The request transports the bounded Route creation contract. */
    public function __construct(
        private readonly int $appId,
        private readonly string $hostname,
        private readonly string $publication,
        private readonly ?int $appInstanceId = null,
        private readonly ?int $nodeId = null,
        private readonly ?int $clusterId = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/routes';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): RouteResponse
    {
        return RouteResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    /** @return array<string, int|string> */
    protected function defaultBody(): array
    {
        return array_filter(
            [
                'app_id' => $this->appId,
                'hostname' => $this->hostname,
                'publication' => $this->publication,
                'app_instance_id' => $this->appInstanceId,
                'node_id' => $this->nodeId,
                'cluster_id' => $this->clusterId,
            ],
            static fn (int|string|null $value): bool => $value !== null,
        );
    }
}
