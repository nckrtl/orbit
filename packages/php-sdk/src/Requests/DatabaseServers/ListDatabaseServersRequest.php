<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\DatabaseServers;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\DatabaseServers\DatabaseServerResponse;
use Orbit\Sdk\Responses\DatabaseServers\DatabaseServersResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListDatabaseServersRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/database-servers';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): DatabaseServersResponse
    {
        $requestId = $this->successRequestId($response);
        $servers = [];

        foreach ($this->unwrapDataList($response) as $server) {
            $servers[] = DatabaseServerResponse::fromGatewayData($server, $requestId);
        }

        return new DatabaseServersResponse($servers, $requestId);
    }
}
