<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\ProxyCli;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\ProxyCli\ProxyCliProviderResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use SensitiveParameter;

final class ShowProxyCliProviderRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $provider,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/proxycli/providers/'.rawurlencode($this->provider);
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): ProxyCliProviderResponse
    {
        return ProxyCliProviderResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }
}
