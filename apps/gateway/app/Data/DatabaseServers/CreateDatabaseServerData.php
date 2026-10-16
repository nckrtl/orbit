<?php

declare(strict_types=1);

namespace App\Data\DatabaseServers;

use App\Domain\DatabaseConnections\DatabaseDriver;
use Spatie\LaravelData\Data;

/**
 * `processId` and `connection` adopt an existing Valkey Process (ADR 0221). The adopted Process supplies the port, so
 * `port` is null then.
 */
final class CreateDatabaseServerData extends Data
{
    public function __construct(
        public string $slug,
        public int $nodeId,
        public DatabaseDriver $driver,
        public string $tag,
        public ?int $port,
        public ?int $processId = null,
        public ?string $connection = null,
    ) {}
}
