<?php

declare(strict_types=1);

namespace App\Data\DatabaseConnections;

use SensitiveParameter;
use Spatie\LaravelData\Data;

final class CreateManagedMysqlUserData extends Data
{
    public function __construct(
        public string $slug,
        public string $database,
        public string $username,
        #[SensitiveParameter]
        public string $password,
    ) {}
}
