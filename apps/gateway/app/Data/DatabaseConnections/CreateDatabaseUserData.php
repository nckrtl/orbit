<?php

declare(strict_types=1);

namespace App\Data\DatabaseConnections;

use SensitiveParameter;
use Spatie\LaravelData\Data;

final class CreateDatabaseUserData extends Data
{
    public function __construct(
        public string $username,
        #[SensitiveParameter]
        public string $password,
        public bool $readOnly,
    ) {}
}
