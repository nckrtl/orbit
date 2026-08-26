<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Gateway;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Gateway\GatewayStatusResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowGatewayStatusRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/gateway/status';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): GatewayStatusResponse
    {
        $data = $this->unwrapData($response);

        return new GatewayStatusResponse(
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            status: is_string($data['status'] ?? null) ? $data['status'] : '',
            version: is_string($data['version'] ?? null) ? $data['version'] : '',
            phpVersion: is_string($data['php_version'] ?? null) ? $data['php_version'] : '',
            laravelVersion: is_string($data['laravel_version'] ?? null) ? $data['laravel_version'] : '',
            requestId: $this->successRequestId($response),
        );
    }
}
