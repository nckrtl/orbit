<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\DatabaseServers;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\DatabaseServers\DatabaseServerResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class CreateDatabaseServerRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $slug,
        private readonly int $nodeId,
        private readonly ?string $tag = null,
        private readonly ?int $port = null,
        private readonly ?string $driver = null,
        private readonly ?int $processId = null,
        private readonly ?string $connection = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/database-servers';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): DatabaseServerResponse
    {
        return DatabaseServerResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    /** @return array<string, int|string> */
    protected function defaultBody(): array
    {
        $body = [
            'slug' => $this->slug,
            'node_id' => $this->nodeId,
        ];

        if ($this->tag !== null) {
            $body['tag'] = $this->tag;
        }

        if ($this->port !== null) {
            $body['port'] = $this->port;
        }

        foreach (['driver' => $this->driver, 'process_id' => $this->processId, 'connection' => $this->connection] as $key => $value) {
            if ($value !== null) {
                $body[$key] = $value;
            }
        }

        return $body;
    }
}
