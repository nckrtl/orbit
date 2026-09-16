<?php

declare(strict_types=1);

namespace App\Services\Database;

final readonly class LocalDatabaseQueryResult
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

    /**
     * @return array{columns: list<string>, rows: list<array<string, bool|float|int|string|null>>, row_count: int, truncated: bool}
     */
    public function toArray(): array
    {
        return [
            'columns' => $this->columns,
            'rows' => $this->rows,
            'row_count' => $this->rowCount,
            'truncated' => $this->truncated,
        ];
    }
}
