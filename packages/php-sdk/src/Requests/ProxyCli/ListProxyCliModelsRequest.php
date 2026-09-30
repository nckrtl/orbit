<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\ProxyCli;

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\ProxyCli\ProxyCliModelResponse;
use Orbit\Sdk\Responses\ProxyCli\ProxyCliModelsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use SensitiveParameter;

final class ListProxyCliModelsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/proxycli/models';
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): ProxyCliModelsResponse
    {
        $requestId = $this->successRequestId($response);
        $rows = $this->unwrapDataList($response, rejectMalformed: true);

        if (count($rows) > 10_000) {
            throw new GatewayApiException('Gateway response contains invalid proxycli models.', requestId: $requestId);
        }

        return new ProxyCliModelsResponse(
            array_map(
                static fn (array $row): ProxyCliModelResponse => ProxyCliModelResponse::fromGatewayData($row, $requestId),
                $rows,
            ),
            $requestId,
        );
    }
}
