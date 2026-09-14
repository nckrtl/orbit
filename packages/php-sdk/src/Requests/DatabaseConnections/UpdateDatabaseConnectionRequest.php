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

final class UpdateDatabaseConnectionRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::PATCH;

    public function __construct(
        private readonly string $slug,
        private readonly bool $hasDriver = false,
        private readonly ?string $driver = null,
        private readonly bool $hasNodeId = false,
        private readonly ?int $nodeId = null,
        private readonly bool $hasHost = false,
        private readonly ?string $host = null,
        private readonly bool $hasPort = false,
        private readonly ?int $port = null,
        private readonly bool $hasDatabase = false,
        private readonly ?string $database = null,
        private readonly bool $hasPath = false,
        private readonly ?string $path = null,
        private readonly bool $hasUsername = false,
        private readonly ?string $username = null,
        private readonly bool $hasPassword = false,
        #[SensitiveParameter]
        private readonly ?string $password = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/database-connections/'.rawurlencode($this->slug);
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): DatabaseConnectionResponse
    {
        return DatabaseConnectionResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    /** @return array<string, int|string|null> */
    protected function defaultBody(): array
    {
        $body = [];

        if ($this->hasDriver) {
            $body['driver'] = $this->driver;
        }

        if ($this->hasNodeId) {
            $body['node_id'] = $this->nodeId;
        }

        if ($this->hasHost) {
            $body['host'] = $this->host;
        }

        if ($this->hasPort) {
            $body['port'] = $this->port;
        }

        if ($this->hasDatabase) {
            $body['database'] = $this->database;
        }

        if ($this->hasPath) {
            $body['path'] = $this->path;
        }

        if ($this->hasUsername) {
            $body['username'] = $this->username;
        }

        if ($this->hasPassword) {
            $body['password'] = $this->password;
        }

        return $body;
    }
}
