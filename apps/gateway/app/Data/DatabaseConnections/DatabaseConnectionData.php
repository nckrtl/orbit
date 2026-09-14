<?php

declare(strict_types=1);

namespace App\Data\DatabaseConnections;

use App\Models\DatabaseConnection;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class DatabaseConnectionData extends Data
{
    public function __construct(
        public int $id,
        public string $slug,
        public string $driver,
        public ?int $nodeId,
        public ?string $host,
        public ?int $port,
        public ?string $database,
        public ?string $path,
        public ?string $username,
        public bool $hasPassword,
    ) {}

    public static function fromModel(DatabaseConnection $connection): self
    {
        return new self(
            id: $connection->id,
            slug: $connection->slug,
            driver: $connection->driver->value,
            nodeId: $connection->node_id,
            host: $connection->host,
            port: $connection->port,
            database: $connection->database,
            path: $connection->path,
            username: $connection->username,
            hasPassword: $connection->password !== null,
        );
    }
}
