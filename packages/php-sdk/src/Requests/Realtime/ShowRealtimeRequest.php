<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Realtime;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Realtime\RealtimeResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowRealtimeRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/realtime';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): RealtimeResponse
    {
        $data = $this->unwrapData($response);
        $requestId = $this->successRequestId($response);

        return RealtimeResponse::fromGatewayData($data, $requestId);
    }
}
