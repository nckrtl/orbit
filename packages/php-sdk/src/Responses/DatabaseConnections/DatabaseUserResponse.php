<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\DatabaseConnections;

final readonly class DatabaseUserResponse
{
    public function __construct(
        public int $id,
        public int $databaseConnectionId,
        public string $username,
        public string $privileges,
        public ?string $createdBy,
        public string $createdAt,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(array $data): self
    {
        return new self(
            id: is_int($data['id'] ?? null) ? $data['id'] : 0,
            databaseConnectionId: is_int($data['database_connection_id'] ?? null) ? $data['database_connection_id'] : 0,
            username: is_string($data['username'] ?? null) ? $data['username'] : '',
            privileges: is_string($data['privileges'] ?? null) ? $data['privileges'] : '',
            createdBy: is_string($data['created_by'] ?? null) ? $data['created_by'] : null,
            createdAt: is_string($data['created_at'] ?? null) ? $data['created_at'] : '',
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'database_connection_id' => $this->databaseConnectionId,
            'username' => $this->username,
            'privileges' => $this->privileges,
            'created_by' => $this->createdBy,
            'created_at' => $this->createdAt,
        ];
    }
}
