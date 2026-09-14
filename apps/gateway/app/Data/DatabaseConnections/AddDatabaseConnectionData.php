<?php

declare(strict_types=1);

namespace App\Data\DatabaseConnections;

use App\Domain\DatabaseConnections\DatabaseDriver;
use Spatie\LaravelData\Data;

final class AddDatabaseConnectionData extends Data
{
    public function __construct(
        public string $slug,
        public DatabaseDriver $driver,
        public ?int $nodeId,
        public ?string $host,
        public ?int $port,
        public ?string $database,
        public ?string $path,
        public ?string $username,
        public ?string $password,
    ) {}
}
