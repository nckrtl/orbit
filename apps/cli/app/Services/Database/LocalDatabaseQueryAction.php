<?php

declare(strict_types=1);

namespace App\Services\Database;

use PDO;
use PDOException;

final readonly class LocalDatabaseQueryAction
{
    public const int ROW_LIMIT = 500;

    public const int SQL_MAX_LENGTH = 16384;

    public const int PATH_MAX_LENGTH = 1024;

    public function __construct(
        private DynamicPdoConnection $connections,
    ) {}

    public function execute(LocalDatabaseQueryRequest $request): LocalDatabaseQueryResult
    {
        InternalDatabaseLane::assertToken($request->token);
        $path = $this->path($request->path);
        $sql = $this->sql($request->sql);

        try {
            $statement = $this->connections->connect([
                'driver' => 'sqlite',
                'path' => $path,
            ], $request->write)->query($sql);
            if ($statement === false) {
                throw new LocalDatabaseQueryException(
                    'database.query_failed',
                    'Database query failed.',
                );
            }

            if (! $request->write && $statement->columnCount() === 0) {
                return new LocalDatabaseQueryResult([], [], 0, false);
            }

            $rows = [];
            $columns = [];
            $truncated = false;

            if ($statement->columnCount() > 0) {
                for ($index = 0; $index < $statement->columnCount(); $index++) {
                    $meta = $statement->getColumnMeta($index);
                    $columns[] = is_array($meta) ? (string) $meta['name'] : (string) $index;
                }

                while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                    if (count($rows) >= self::ROW_LIMIT) {
                        $truncated = true;

                        break;
                    }

                    $rows[] = $this->normalizeRow($row);
                }
            }

            return new LocalDatabaseQueryResult(
                $columns,
                $rows,
                $request->write ? max($statement->rowCount(), 0) : count($rows),
                $truncated,
            );
        } catch (PDOException) {
            throw new LocalDatabaseQueryException(
                'database.query_failed',
                'Database query failed.',
            );
        }
    }

    private function path(string $path): string
    {
        if (
            $path === ''
            || strlen($path) > self::PATH_MAX_LENGTH
            || ! str_starts_with($path, '/')
            || str_contains($path, "\0")
        ) {
            throw new LocalDatabaseQueryException(
                'database.query_failed',
                'Database query failed.',
            );
        }

        return $path;
    }

    private function sql(string $sql): string
    {
        $sql = trim($sql);

        if ($sql === '' || strlen($sql) > self::SQL_MAX_LENGTH) {
            throw new LocalDatabaseQueryException(
                'validation.failed',
                'SQL statement is required and must be at most 16384 characters.',
            );
        }

        return $sql;
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
}
