<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Gateway;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Gateway\DesiredFleetStateResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowDesiredFleetStateRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/gateway/desired-fleet-state';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): DesiredFleetStateResponse
    {
        return DesiredFleetStateResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
