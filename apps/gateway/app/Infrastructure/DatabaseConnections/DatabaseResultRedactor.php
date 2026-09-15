<?php

declare(strict_types=1);

namespace App\Infrastructure\DatabaseConnections;

use App\Domain\DatabaseConnections\DatabaseQueryResult;
use App\Domain\DatabaseConnections\DatabaseSchemaTable;
use App\Domain\DatabaseConnections\DatabaseTableColumn;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Models\DatabaseConnection;
use SensitiveParameter;

final readonly class DatabaseResultRedactor
{
    public function __construct(
        private CommandActivityInputSanitizer $sanitizer,
    ) {}

    public function query(
        DatabaseConnection $connection,
        DatabaseQueryResult $result,
    ): DatabaseQueryResult {
        $password = $connection->password;
        $columns = array_map(
            fn (string $column): string => $this->text($column, $password),
            $result->columns,
        );
        $rows = [];

        foreach ($result->rows as $row) {
            $redacted = [];

            foreach ($row as $key => $value) {
                $redacted[$this->text((string) $key, $password)] = is_string($value)
                    ? $this->text($value, $password)
                    : $value;
            }

            $rows[] = $redacted;
        }

        return new DatabaseQueryResult($columns, $rows, $result->rowCount, $result->truncated);
    }

    /**
     * @param  list<string>  $tables
     * @return list<string>
     */
    public function tables(#[SensitiveParameter] ?string $password, array $tables): array
    {
        return array_map(fn (string $table): string => $this->text($table, $password), $tables);
    }

    /**
     * @param  list<DatabaseSchemaTable>  $tables
     * @return list<DatabaseSchemaTable>
     */
    public function schema(#[SensitiveParameter] ?string $password, array $tables): array
    {
        return array_map(
            fn (DatabaseSchemaTable $table): DatabaseSchemaTable => new DatabaseSchemaTable(
                $this->text($table->name, $password),
                $this->columns($password, $table->columns),
            ),
            $tables,
        );
    }

    /**
     * @param  list<DatabaseTableColumn>  $columns
     * @return list<DatabaseTableColumn>
     */
    public function columns(#[SensitiveParameter] ?string $password, array $columns): array
    {
        return array_map(
            fn (DatabaseTableColumn $column): DatabaseTableColumn => new DatabaseTableColumn(
                $this->text($column->name, $password),
                $this->text($column->type, $password),
                $column->nullable,
                $column->default === null ? null : $this->text($column->default, $password),
                $column->primary,
            ),
            $columns,
        );
    }

    public function text(string $value, #[SensitiveParameter] ?string $password): string
    {
        $redacted = $this->sanitizer->redactText($value);

        if (is_string($password) && $password !== '') {
            $redacted = str_replace($password, '[REDACTED]', $redacted);
        }

        return $redacted;
    }
}
