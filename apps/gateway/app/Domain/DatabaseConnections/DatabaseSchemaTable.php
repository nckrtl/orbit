<?php

declare(strict_types=1);

namespace App\Domain\DatabaseConnections;

final readonly class DatabaseSchemaTable
{
    /**
     * @param  list<DatabaseTableColumn>  $columns
     */
    public function __construct(
        public string $name,
        public array $columns,
    ) {}

    /** @return array{name: string, columns: list<array{name: string, type: string, nullable: bool, default: string|null, primary: bool}>} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'columns' => array_map(
                static fn (DatabaseTableColumn $column): array => $column->toArray(),
                $this->columns,
            ),
        ];
    }
}
