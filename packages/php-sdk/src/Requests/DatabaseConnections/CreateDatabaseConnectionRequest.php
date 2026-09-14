<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\DatabaseConnections;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseConnectionResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;
use SensitiveParameter;

final class CreateDatabaseConnectionRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $slug,
        private readonly string $driver,
        private readonly ?int $nodeId = null,
        private readonly ?string $host = null,
        private readonly ?int $port = null,
        private readonly ?string $database = null,
        private readonly ?string $path = null,
        private readonly ?string $username = null,
        #[SensitiveParameter]
        private readonly ?string $password = null,
        private readonly bool $hasPassword = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/database-connections';
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): DatabaseConnectionResponse
    {
        return DatabaseConnectionResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    /** @return array<string, int|string|null> */
    protected function defaultBody(): array
    {
        $body = [
            'slug' => $this->slug,
            'driver' => $this->driver,
        ];

        if ($this->nodeId !== null) {
            $body['node_id'] = $this->nodeId;
        }

        if ($this->host !== null) {
            $body['host'] = $this->host;
        }

        if ($this->port !== null) {
            $body['port'] = $this->port;
        }

        if ($this->database !== null) {
            $body['database'] = $this->database;
        }

        if ($this->path !== null) {
            $body['path'] = $this->path;
        }

        if ($this->username !== null) {
            $body['username'] = $this->username;
        }

        if ($this->hasPassword) {
            $body['password'] = $this->password;
        }

        return $body;
    }
}
