<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\ProxyCli;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\ProxyCli\ProxyCliStatusResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use SensitiveParameter;

final class ShowProxyCliStatusRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/proxycli';
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): ProxyCliStatusResponse
    {
        return ProxyCliStatusResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }
}
