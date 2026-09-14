<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\DatabaseConnections;

use InvalidArgumentException;
use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;

final readonly class DatabaseConnectionsResponse
{
    /** @var list<DatabaseConnectionResponse> */
    public array $connections;

    public string $requestId;

    /**
     * @param  array<array-key, mixed>  $connections
     */
    public function __construct(
        #[SensitiveParameter]
        array $connections,
        #[SensitiveParameter]
        string $requestId,
    ) {
        if (! array_is_list($connections)) {
            throw new InvalidArgumentException('Invalid Database connection collection response.');
        }

        foreach ($connections as $connection) {
            if (! $connection instanceof DatabaseConnectionResponse) {
                throw new InvalidArgumentException('Invalid Database connection collection response.');
            }
        }

        /** @var list<DatabaseConnectionResponse> $connections */
        $this->connections = $connections;
        $this->requestId = GatewayRequestId::fromTransport($requestId) ?? '';
    }

    /**
     * @param  array<array-key, mixed>  $connections
     */
    public static function fromConnections(array $connections, string $requestId): self
    {
        return new self($connections, $requestId);
    }

    /** @return array{connections: list<array<string, bool|int|string|null>>, request_id: string} */
    public function toArray(): array
    {
        return [
            'connections' => array_map(
                static function (DatabaseConnectionResponse $connection): array {
                    $data = $connection->toArray();
                    unset($data['request_id']);

                    return $data;
                },
                $this->connections,
            ),
            'request_id' => $this->requestId,
        ];
    }
}
