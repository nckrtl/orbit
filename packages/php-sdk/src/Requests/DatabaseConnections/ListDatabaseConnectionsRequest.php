<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\DatabaseConnections;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseConnectionResponse;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseConnectionsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListDatabaseConnectionsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/database-connections';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): DatabaseConnectionsResponse
    {
        $data = $this->unwrapDataList($response);
        $requestId = $this->successRequestId($response);
        $connections = [];

        foreach ($data as $connection) {
            $connections[] = DatabaseConnectionResponse::fromGatewayData($connection, $requestId);
        }

        return DatabaseConnectionsResponse::fromConnections($connections, $requestId);
    }
}
