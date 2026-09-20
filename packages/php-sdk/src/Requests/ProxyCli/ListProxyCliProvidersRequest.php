<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\ProxyCli;

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\ProxyCli\ProxyCliProviderResponse;
use Orbit\Sdk\Responses\ProxyCli\ProxyCliProvidersResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use SensitiveParameter;

final class ListProxyCliProvidersRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/proxycli/providers';
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): ProxyCliProvidersResponse
    {
        $requestId = $this->successRequestId($response);
        $rows = $this->unwrapDataList($response, rejectMalformed: true);

        if (count($rows) > 1_000) {
            throw new GatewayApiException('Gateway response contains invalid proxycli providers.', requestId: $requestId);
        }

        return new ProxyCliProvidersResponse(
            array_map(
                static fn (array $row): ProxyCliProviderResponse => ProxyCliProviderResponse::fromGatewayData($row, $requestId),
                $rows,
            ),
            $requestId,
        );
    }
}
