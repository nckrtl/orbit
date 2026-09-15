<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\DatabaseConnections;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseDescribeResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class DescribeDatabaseTableRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $slug,
        private readonly string $table,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/database-connections/'.rawurlencode($this->slug).'/describe/'.rawurlencode($this->table);
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): DatabaseDescribeResponse
    {
        return DatabaseDescribeResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
