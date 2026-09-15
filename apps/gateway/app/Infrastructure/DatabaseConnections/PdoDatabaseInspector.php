<?php

declare(strict_types=1);

namespace App\Infrastructure\DatabaseConnections;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseConnections\DatabaseQueryResult;
use App\Domain\DatabaseConnections\DatabaseSchemaTable;
use App\Domain\DatabaseConnections\DatabaseTableColumn;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use PDO;
use PDOException;
use SensitiveParameter;
use Throwable;

final readonly class PdoDatabaseInspector
{
    public const int ROW_LIMIT = 500;

    /**
     * @param  (callable(DatabaseConnection): PDO)|null  $connector
     */
    public function __construct(
        private DatabaseResultRedactor $redactor,
        private mixed $connector = null,
    ) {}

    public function query(DatabaseConnection $connection, string $sql, bool $write): DatabaseQueryResult
    {
        $pdo = $this->pdo($connection);

        try {
            $statement = $pdo->query($sql);
        } catch (PDOException $exception) {
            $this->fail($connection, $exception);
        }

        if ($statement === false) {
            $this->fail($connection);
        }

        if (! $write && $statement->columnCount() === 0) {
            return $this->redactor->query($connection, new DatabaseQueryResult([], [], 0, false));
        }

        $rows = [];
        $columns = [];
        $truncated = false;

        if ($statement->columnCount() > 0) {
            for ($index = 0; $index < $statement->columnCount(); $index++) {
                $meta = $statement->getColumnMeta($index);
                $columns[] = is_array($meta) ? $meta['name'] : (string) $index;
            }

            while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                if (count($rows) >= self::ROW_LIMIT) {
                    $truncated = true;

                    break;
                }

                $rows[] = $this->normalizeRow($row);
            }
        }

        return $this->redactor->query(
            $connection,
            new DatabaseQueryResult($columns, $rows, $write ? max($statement->rowCount(), 0) : count($rows), $truncated),
        );
    }

    /** @return list<string> */
    public function tables(DatabaseConnection $connection): array
    {
        $sql = match ($connection->driver) {
            DatabaseDriver::Mysql => 'SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = \'BASE TABLE\' ORDER BY TABLE_NAME',
            DatabaseDriver::Pgsql => 'SELECT tablename AS name FROM pg_catalog.pg_tables WHERE schemaname = current_schema() ORDER BY tablename',
            DatabaseDriver::Sqlite => 'SELECT name FROM sqlite_master WHERE type = \'table\' AND name NOT LIKE \'sqlite_%\' ORDER BY name',
        };
        $result = $this->query($connection, $sql, false);
        $tables = [];

        foreach ($result->rows as $row) {
            $name = $row['name'] ?? $row['TABLE_NAME'] ?? $row['tablename'] ?? null;

            if (is_string($name) && $name !== '') {
                $tables[] = $name;
            }
        }

        return $this->redactor->tables($connection->password, $tables);
    }

    /** @return list<DatabaseSchemaTable> */
    public function schema(DatabaseConnection $connection): array
    {
        $tables = [];

        foreach ($this->tables($connection) as $table) {
            $tables[] = new DatabaseSchemaTable($table, $this->describe($connection, $table));
        }

        return $this->redactor->schema($connection->password, $tables);
    }

    /** @return list<DatabaseTableColumn> */
    public function describe(DatabaseConnection $connection, string $table): array
    {
        $columns = match ($connection->driver) {
            DatabaseDriver::Mysql => $this->mysqlColumns($connection, $table),
            DatabaseDriver::Pgsql => $this->pgsqlColumns($connection, $table),
            DatabaseDriver::Sqlite => $this->sqliteColumns($connection, $table),
        };

        if ($columns === []) {
            throw new ResourceOperationException(
                errorCode: 'database.table_missing',
                message: "Table [{$table}] was not found.",
                status: 404,
            );
        }

        return $this->redactor->columns($connection->password, $columns);
    }

    public function dsn(DatabaseConnection $connection): string
    {
        return match ($connection->driver) {
            DatabaseDriver::Mysql => sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $connection->host ?? '',
                $connection->port ?? 3306,
                $connection->database ?? '',
            ),
            DatabaseDriver::Pgsql => sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $connection->host ?? '',
                $connection->port ?? 5432,
                $connection->database ?? '',
            ),
            DatabaseDriver::Sqlite => 'sqlite:'.$connection->path,
        };
    }

    private function pdo(DatabaseConnection $connection): PDO
    {
        if (is_callable($this->connector)) {
            return ($this->connector)($connection);
        }

        try {
            $pdo = new PDO(
                $this->dsn($connection),
                $connection->username,
                $connection->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_TIMEOUT => 10,
                ],
            );
        } catch (PDOException $exception) {
            $this->fail($connection, $exception);
        }

        return $pdo;
    }

    /** @return list<DatabaseTableColumn> */
    private function mysqlColumns(DatabaseConnection $connection, string $table): array
    {
        $result = $this->query(
            $connection,
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '.$this->quoteLiteral($table).' ORDER BY ORDINAL_POSITION',
            false,
        );

        return array_map(static fn (array $row): DatabaseTableColumn => new DatabaseTableColumn(
            (string) ($row['COLUMN_NAME'] ?? ''),
            (string) ($row['COLUMN_TYPE'] ?? ''),
            ($row['IS_NULLABLE'] ?? '') === 'YES',
            isset($row['COLUMN_DEFAULT']) ? (string) $row['COLUMN_DEFAULT'] : null,
            ($row['COLUMN_KEY'] ?? '') === 'PRI',
        ), $result->rows);
    }

    /** @return list<DatabaseTableColumn> */
    private function pgsqlColumns(DatabaseConnection $connection, string $table): array
    {
        $quoted = $this->quoteLiteral($table);
        $result = $this->query(
            $connection,
            'SELECT a.attname AS name, pg_catalog.format_type(a.atttypid, a.atttypmod) AS type, NOT a.attnotnull AS nullable, pg_get_expr(ad.adbin, ad.adrelid) AS default_value, COALESCE(i.indisprimary, false) AS is_primary FROM pg_catalog.pg_attribute a LEFT JOIN pg_catalog.pg_attrdef ad ON a.attrelid = ad.adrelid AND a.attnum = ad.adnum LEFT JOIN pg_catalog.pg_index i ON a.attrelid = i.indrelid AND a.attnum = ANY (i.indkey) AND i.indisprimary WHERE a.attrelid = '.$quoted.'::regclass AND a.attnum > 0 AND NOT a.attisdropped ORDER BY a.attnum',
            false,
        );

        return array_map(static fn (array $row): DatabaseTableColumn => new DatabaseTableColumn(
            (string) ($row['name'] ?? ''),
            (string) ($row['type'] ?? ''),
            (bool) ($row['nullable'] ?? false),
            isset($row['default_value']) ? (string) $row['default_value'] : null,
            (bool) ($row['is_primary'] ?? false),
        ), $result->rows);
    }

    /** @return list<DatabaseTableColumn> */
    private function sqliteColumns(DatabaseConnection $connection, string $table): array
    {
        $result = $this->query($connection, 'PRAGMA table_info('.$this->quoteIdentifier($table).')', false);

        return array_map(static fn (array $row): DatabaseTableColumn => new DatabaseTableColumn(
            (string) ($row['name'] ?? ''),
            (string) ($row['type'] ?? ''),
            ! (bool) ($row['notnull'] ?? false),
            isset($row['dflt_value']) ? (string) $row['dflt_value'] : null,
            (int) ($row['pk'] ?? 0) > 0,
        ), $result->rows);
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return array<string, bool|float|int|string|null>
     */
    private function normalizeRow(array $row): array
    {
        $normalized = [];

        foreach ($row as $key => $value) {
            $normalized[(string) $key] = $this->scalar($value);
        }

        return $normalized;
    }

    private function scalar(mixed $value): bool|float|int|string|null
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        return is_string($value) ? $value : null;
    }

    private function quoteLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    private function quoteIdentifier(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }

    private function fail(DatabaseConnection $connection, #[SensitiveParameter] ?Throwable $previous = null): never
    {
        throw new ResourceOperationException(
            errorCode: 'database.query_failed',
            message: "Database connection [{$connection->slug}] query failed.",
            status: 502,
            previous: $previous,
        );
    }
}
