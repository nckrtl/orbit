<?php

declare(strict_types=1);

namespace App\Infrastructure\DatabaseConnections;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseConnections\DatabaseInspectionExecutor;
use App\Domain\DatabaseConnections\DatabaseQueryResult;
use App\Domain\DatabaseConnections\DatabaseSchemaTable;
use App\Domain\DatabaseConnections\DatabaseTableColumn;
use App\Models\DatabaseConnection;

final readonly class RegisteredDatabaseInspectionExecutor implements DatabaseInspectionExecutor
{
    public function __construct(
        private SqliteNodeDatabaseInspector $sqlite,
        private PdoDatabaseInspector $pdo,
    ) {}

    public function query(DatabaseConnection $connection, string $sql, bool $write): DatabaseQueryResult
    {
        return $this->inspector($connection)->query($connection, $sql, $write);
    }

    /** @return list<string> */
    public function tables(DatabaseConnection $connection): array
    {
        return $this->inspector($connection)->tables($connection);
    }

    /** @return list<DatabaseSchemaTable> */
    public function schema(DatabaseConnection $connection): array
    {
        return $this->inspector($connection)->schema($connection);
    }

    /** @return list<DatabaseTableColumn> */
    public function describe(DatabaseConnection $connection, string $table): array
    {
        return $this->inspector($connection)->describe($connection, $table);
    }

    private function inspector(DatabaseConnection $connection): PdoDatabaseInspector|SqliteNodeDatabaseInspector
    {
        return $connection->driver === DatabaseDriver::Sqlite ? $this->sqlite : $this->pdo;
    }
}
