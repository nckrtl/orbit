<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\DatabaseServers;

use Orbit\Sdk\Support\GatewayRequestId;

final readonly class DatabaseServersResponse
{
    public string $requestId;

    /** @param list<DatabaseServerResponse> $servers */
    public function __construct(
        public array $servers,
        string $requestId,
    ) {
        $this->requestId = GatewayRequestId::fromTransport($requestId) ?? '';
    }

    /** @return array{servers: list<array<string, mixed>>, request_id: string} */
    public function toArray(): array
    {
        return [
            'servers' => array_map(
                static function (DatabaseServerResponse $server): array {
                    $data = $server->toArray();
                    unset($data['request_id']);

                    return $data;
                },
                $this->servers,
            ),
            'request_id' => $this->requestId,
        ];
    }
}
