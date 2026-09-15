<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\DatabaseConnections\DatabaseInspectionExecutor;
use App\Domain\DatabaseConnections\DatabaseQueryResult;
use App\Domain\DatabaseConnections\DatabaseSchemaTable;
use App\Domain\DatabaseConnections\DatabaseTableColumn;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;

final class FakeDatabaseInspectionExecutor implements DatabaseInspectionExecutor
{
    /** @var list<array{slug: string, sql: string, write: bool}> */
    public array $queries = [];

    public function __construct(
        public DatabaseQueryResult $queryResult = new DatabaseQueryResult(['id'], [['id' => 1]], 1, false),
        /** @var list<string> */
        public array $tables = ['users'],
        /** @var list<DatabaseSchemaTable> */
        public array $schema = [],
        /** @var list<DatabaseTableColumn> */
        public array $columns = [],
        public ?ResourceOperationException $failure = null,
    ) {
        if ($this->schema === []) {
            $this->schema = [
                new DatabaseSchemaTable('users', [
                    new DatabaseTableColumn('id', 'INTEGER', false, null, true),
                    new DatabaseTableColumn('email', 'TEXT', false, null, false),
                ]),
            ];
        }

        if ($this->columns === []) {
            $this->columns = $this->schema[0]->columns;
        }
    }

    public function query(DatabaseConnection $connection, string $sql, bool $write): DatabaseQueryResult
    {
        $this->queries[] = [
            'slug' => $connection->slug,
            'sql' => $sql,
            'write' => $write,
        ];

        if ($this->failure instanceof ResourceOperationException) {
            throw $this->failure;
        }

        return $this->queryResult;
    }

    /** @return list<string> */
    public function tables(DatabaseConnection $connection): array
    {
        if ($this->failure instanceof ResourceOperationException) {
            throw $this->failure;
        }

        return $this->tables;
    }

    /** @return list<DatabaseSchemaTable> */
    public function schema(DatabaseConnection $connection): array
    {
        if ($this->failure instanceof ResourceOperationException) {
            throw $this->failure;
        }

        return $this->schema;
    }

    /** @return list<DatabaseTableColumn> */
    public function describe(DatabaseConnection $connection, string $table): array
    {
        if ($this->failure instanceof ResourceOperationException) {
            throw $this->failure;
        }

        return $this->columns;
    }
}
