<?php

declare(strict_types=1);

namespace App\Domain\DatabaseConnections;

final readonly class DatabaseQueryResult
{
    /**
     * @param  list<string>  $columns
     * @param  list<array<string, bool|float|int|string|null>>  $rows
     */
    public function __construct(
        public array $columns,
        public array $rows,
        public int $rowCount,
        public bool $truncated,
    ) {}
}
