<?php

declare(strict_types=1);

namespace App\Domain\DatabaseConnections;

use App\Domain\Shared\ResourceOperationException;

final readonly class DatabaseSqlClassifier
{
    public const int SQL_MAX_LENGTH = 16384;

    /** @var list<string> */
    private const array WRITE_VERBS = [
        'ALTER',
        'ANALYZE',
        'ATTACH',
        'BEGIN',
        'CALL',
        'COMMENT',
        'COMMIT',
        'COPY',
        'CREATE',
        'DELETE',
        'DETACH',
        'DO',
        'DROP',
        'GRANT',
        'INSERT',
        'LOAD',
        'LOCK',
        'MERGE',
        'REINDEX',
        'REPLACE',
        'REVOKE',
        'ROLLBACK',
        'SAVEPOINT',
        'TRUNCATE',
        'UNLOCK',
        'UPDATE',
        'VACUUM',
    ];

    /** @var list<string> */
    private const array READ_PRAGMAS = [
        'collation_list',
        'compile_options',
        'database_list',
        'foreign_key_list',
        'function_list',
        'index_info',
        'index_list',
        'index_xinfo',
        'module_list',
        'pragma_list',
        'table_info',
        'table_list',
        'table_xinfo',
    ];

    public function normalize(string $sql): string
    {
        $sql = trim($sql);

        if ($sql === '' || strlen($sql) > self::SQL_MAX_LENGTH) {
            throw new ResourceOperationException(
                errorCode: 'validation.failed',
                message: 'SQL statement is required and must be at most 16384 characters.',
            );
        }

        return $sql;
    }

    public function assertSingleStatement(string $sql): void
    {
        if ($this->statementCount($sql) > 1) {
            throw new ResourceOperationException(
                errorCode: 'database.sql_multiple_statements',
                message: 'Database inspection accepts one SQL statement.',
            );
        }
    }

    public function isWrite(string $sql): bool
    {
        $normalized = $this->stripComments($sql);
        $verb = $this->leadingVerb($normalized);

        if ($verb === '') {
            return true;
        }

        if ($verb === 'PRAGMA') {
            return ! $this->isReadPragma($normalized);
        }

        if (in_array($verb, self::WRITE_VERBS, true)) {
            return true;
        }

        if ($verb === 'WITH' && $this->commonTableExpressionWrites($normalized)) {
            return true;
        }

        return false;
    }

    public function assertWriteAllowed(string $sql, bool $write): void
    {
        $this->assertSingleStatement($sql);

        if ($this->isWrite($sql) && ! $write) {
            throw new ResourceOperationException(
                errorCode: 'database.write_required',
                message: 'Write SQL requires the write flag.',
            );
        }
    }

    private function statementCount(string $sql): int
    {
        $normalized = trim($this->stripComments($sql), " \t\n\r\0\x0B;");

        if ($normalized === '') {
            return 0;
        }

        $count = 1;
        $length = strlen($normalized);
        $inSingle = false;
        $inDouble = false;

        for ($index = 0; $index < $length; $index++) {
            $character = $normalized[$index];

            if ($character === "'" && ! $inDouble) {
                $inSingle = ! $inSingle;

                continue;
            }

            if ($character === '"' && ! $inSingle) {
                $inDouble = ! $inDouble;

                continue;
            }

            if ($character === ';' && ! $inSingle && ! $inDouble) {
                $remainder = trim(substr($normalized, $index + 1));

                if ($remainder !== '') {
                    $count++;
                }
            }
        }

        return $count;
    }

    private function stripComments(string $sql): string
    {
        $withoutBlock = preg_replace('/\/\*.*?\*\//s', ' ', $sql) ?? $sql;

        return preg_replace('/--[^\n]*/', ' ', $withoutBlock) ?? $withoutBlock;
    }

    private function leadingVerb(string $sql): string
    {
        if (preg_match('/\A\s*([A-Za-z]+)/', $sql, $matches) !== 1) {
            return '';
        }

        return strtoupper($matches[1]);
    }

    private function isReadPragma(string $sql): bool
    {
        if (preg_match('/\A\s*PRAGMA\s+([A-Za-z_]+)/i', $sql, $matches) !== 1) {
            return false;
        }

        return in_array(strtolower($matches[1]), self::READ_PRAGMAS, true);
    }

    private function commonTableExpressionWrites(string $sql): bool
    {
        return preg_match(
            '/\b(?:INSERT|UPDATE|DELETE|REPLACE|CREATE|DROP|ALTER|TRUNCATE|GRANT|REVOKE)\b/i',
            $sql,
        ) === 1;
    }
}
