<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\DatabaseConnections;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseQueryResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;
use SensitiveParameter;

final class QueryDatabaseConnectionRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $slug,
        #[SensitiveParameter]
        private readonly string $sql,
        private readonly bool $write = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/database-connections/'.rawurlencode($this->slug).'/query';
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): DatabaseQueryResponse
    {
        return DatabaseQueryResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    /** @return array{sql: string, write: bool} */
    protected function defaultBody(): array
    {
        return [
            'sql' => $this->sql,
            'write' => $this->write,
        ];
    }
}
