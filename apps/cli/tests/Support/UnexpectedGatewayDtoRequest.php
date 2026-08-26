<?php

declare(strict_types=1);

namespace Tests\Support;

use Orbit\Sdk\GatewayRequest;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use stdClass;

final class UnexpectedGatewayDtoRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/test-unexpected-dto';
    }

    public function createDtoFromResponse(Response $response): object
    {
        $response = new stdClass;
        $response->credential = 'unexpected-dto-secret';

        return $response;
    }
}
