<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\DatabaseConnections;

use InvalidArgumentException;
use Orbit\Sdk\Support\GatewayRequestId;

final readonly class DatabaseConnectionsResponse
{
    /** @param list<DatabaseConnectionResponse> $connections */
    private function __construct(
        public array $connections,
        public string $requestId,
    ) {}

    /**
     * @param  list<DatabaseConnectionResponse>  $connections
     */
    public static function fromConnections(array $connections, string $requestId): self
    {
        if (! array_is_list($connections)) {
            throw new InvalidArgumentException('Invalid Database connection collection response.');
        }

        foreach ($connections as $connection) {
            if (! $connection instanceof DatabaseConnectionResponse) {
                throw new InvalidArgumentException('Invalid Database connection collection response.');
            }
        }

        return new self($connections, GatewayRequestId::fromTransport($requestId) ?? '');
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
