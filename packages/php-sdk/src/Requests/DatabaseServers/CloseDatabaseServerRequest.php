<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\DatabaseServers;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\DatabaseServers\DatabaseServerResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class CloseDatabaseServerRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $slug,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/database-servers/'.rawurlencode($this->slug).'/close';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): DatabaseServerResponse
    {
        return DatabaseServerResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
