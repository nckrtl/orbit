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

    /**
     * @param  list<string>|null  $patterns
     * @param  list<string>|null  $allow
     */
    public function __construct(
        private readonly string $slug,
        private readonly ?string $driver = null,
        private readonly ?int $nodeId = null,
        private readonly ?string $host = null,
        private readonly ?int $port = null,
        private readonly string|int|null $database = null,
        private readonly ?string $path = null,
        private readonly ?string $username = null,
        #[SensitiveParameter]
        private readonly ?string $password = null,
        private readonly bool $hasPassword = false,
        private readonly ?string $server = null,
        private readonly ?int $instanceId = null,
        private readonly ?array $patterns = null,
        private readonly ?array $allow = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/database-connections';
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): DatabaseConnectionResponse
    {
        return DatabaseConnectionResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    /** @return array<string, int|string|list<string>|null> */
    protected function defaultBody(): array
    {
        $body = ['slug' => $this->slug];

        if ($this->server !== null) {
            $body['server'] = $this->server;

            if ($this->instanceId !== null) {
                $body['instance_id'] = $this->instanceId;
            }

            foreach (['patterns' => $this->patterns, 'allow' => $this->allow, 'database' => $this->database] as $key => $value) {
                if ($value !== null) {
                    $body[$key] = $value;
                }
            }

            return $body;
        }

        if ($this->driver !== null) {
            $body['driver'] = $this->driver;
        }

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
