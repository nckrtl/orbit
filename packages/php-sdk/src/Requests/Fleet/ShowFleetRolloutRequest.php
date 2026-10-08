<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Fleet;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Fleet\FleetRolloutStatusResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowFleetRolloutRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/fleet/rollout';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): FleetRolloutStatusResponse
    {
        return FleetRolloutStatusResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
