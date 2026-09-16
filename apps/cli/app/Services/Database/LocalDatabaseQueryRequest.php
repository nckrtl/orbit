<?php

declare(strict_types=1);

namespace App\Services\Database;

final readonly class LocalDatabaseQueryRequest
{
    public function __construct(
        public string $token,
        public string $path,
        public string $sql,
        public bool $write,
    ) {}
}
