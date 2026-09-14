<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\DatabaseConnections;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseConnectionResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class DestroyDatabaseConnectionRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly string $slug,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/database-connections/'.rawurlencode($this->slug);
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): DatabaseConnectionResponse
    {
        return DatabaseConnectionResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
