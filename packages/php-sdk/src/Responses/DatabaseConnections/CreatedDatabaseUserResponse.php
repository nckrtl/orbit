<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\DatabaseConnections;

use Orbit\Sdk\Support\GatewayRequestId;

final readonly class CreatedDatabaseUserResponse
{
    public string $requestId;

    public function __construct(
        public DatabaseUserResponse $user,
        string $requestId,
    ) {
        $this->requestId = GatewayRequestId::fromTransport($requestId) ?? '';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [...$this->user->toArray(), 'request_id' => $this->requestId];
    }
}
