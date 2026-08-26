<?php

declare(strict_types=1);

namespace Tests\Support;

use Orbit\Sdk\GatewayRequest;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class InvalidGatewayDtoRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/test-invalid-dto';
    }

    public function createDtoFromResponse(Response $response): string
    {
        return 'invalid-dto-secret';
    }
}
