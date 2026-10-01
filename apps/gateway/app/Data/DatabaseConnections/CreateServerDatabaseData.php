<?php

declare(strict_types=1);

namespace App\Data\DatabaseConnections;

use Spatie\LaravelData\Data;

final class CreateServerDatabaseData extends Data
{
    public function __construct(
        public string $slug,
        public string $server,
        public ?int $instanceId,
    ) {}
}
