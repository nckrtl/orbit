<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Gateway;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Gateway\GatewayStatusResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowGatewayStatusRequest extends GatewayRequest
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/gateway/status';
    }

    public function createDtoFromResponse(Response $response): GatewayStatusResponse
    {
        $data = $this->unwrapData($response);
        $meta = $this->unwrapMeta($response);

        return new GatewayStatusResponse(
            name: (string) ($data['name'] ?? ''),
            status: (string) ($data['status'] ?? ''),
            version: (string) ($data['version'] ?? ''),
            phpVersion: (string) ($data['php_version'] ?? ''),
            laravelVersion: (string) ($data['laravel_version'] ?? ''),
            requestId: (string) ($meta['request_id'] ?? ''),
        );
    }
}
