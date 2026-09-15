<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\DatabaseConnections;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseTablesResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListDatabaseTablesRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $slug,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/database-connections/'.rawurlencode($this->slug).'/tables';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): DatabaseTablesResponse
    {
        return DatabaseTablesResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
