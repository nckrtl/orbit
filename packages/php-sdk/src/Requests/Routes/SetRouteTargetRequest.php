<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Routes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Routes\RouteResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class SetRouteTargetRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::PUT;

    /**
     * @param  list<int>|null  $targetIds
     * @param  list<array{app_instance_id: int, route_id?: int, remove?: true}>  $dispositions
     */
    public function __construct(
        private readonly int $routeId,
        private readonly int $appInstanceId = 0,
        private readonly ?array $targetIds = null,
        private readonly array $dispositions = [],
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/routes/{$this->routeId}/target";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): RouteResponse
    {
        return RouteResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        if ($this->targetIds !== null) {
            $body = ['targets' => $this->targetIds];

            if ($this->dispositions !== []) {
                $body['dispositions'] = $this->dispositions;
            }

            return $body;
        }

        return ['app_instance_id' => $this->appInstanceId];
    }
}
