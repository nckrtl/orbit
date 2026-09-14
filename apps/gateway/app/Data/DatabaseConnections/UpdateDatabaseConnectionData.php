<?php

declare(strict_types=1);

namespace App\Data\DatabaseConnections;

use App\Domain\DatabaseConnections\DatabaseDriver;
use Spatie\LaravelData\Data;

final class UpdateDatabaseConnectionData extends Data
{
    public function __construct(
        public bool $driverProvided,
        public ?DatabaseDriver $driver,
        public bool $nodeIdProvided,
        public ?int $nodeId,
        public bool $hostProvided,
        public ?string $host,
        public bool $portProvided,
        public ?int $port,
        public bool $databaseProvided,
        public ?string $database,
        public bool $pathProvided,
        public ?string $path,
        public bool $usernameProvided,
        public ?string $username,
        public bool $passwordProvided,
        public ?string $password,
    ) {}
}
