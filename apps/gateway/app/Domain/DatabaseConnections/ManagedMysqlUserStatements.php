<?php

declare(strict_types=1);

namespace App\Domain\DatabaseConnections;

use App\Domain\Shared\ResourceOperationException;
use SensitiveParameter;

final readonly class ManagedMysqlUserStatements
{
    public const string IDENTIFIER_PATTERN = '/\A[A-Za-z_][A-Za-z0-9_]{0,31}\z/D';

    public function render(string $database, string $username, #[SensitiveParameter] string $password): string
    {
        $this->assertIdentifier($database, 'database');
        $this->assertIdentifier($username, 'username');

        if ($password === '' || str_contains($password, "\0") || str_contains($password, "\r") || str_contains($password, "\n")) {
            throw new ResourceOperationException(
                errorCode: 'validation.failed',
                message: 'A managed MySQL user requires a single-line password.',
                status: 422,
            );
        }

        $quotedDatabase = $this->quoteIdentifier($database);
        $quotedUsername = $this->quoteString($username);
        $quotedPassword = $this->quoteString($password);

        return implode("\n", [
            "CREATE DATABASE IF NOT EXISTS {$quotedDatabase};",
            "CREATE USER IF NOT EXISTS {$quotedUsername}@'%' IDENTIFIED BY {$quotedPassword};",
            "ALTER USER {$quotedUsername}@'%' IDENTIFIED BY {$quotedPassword};",
            "GRANT ALL PRIVILEGES ON {$quotedDatabase}.* TO {$quotedUsername}@'%';",
            'FLUSH PRIVILEGES;',
        ])."\n";
    }

    public function __debugInfo(): array
    {
        return [];
    }

    private function assertIdentifier(string $value, string $field): void
    {
        if (preg_match(self::IDENTIFIER_PATTERN, $value) !== 1) {
            throw new ResourceOperationException(
                errorCode: 'validation.failed',
                message: "A managed MySQL {$field} must be a 1-32 character identifier.",
                status: 422,
            );
        }
    }

    private function quoteIdentifier(string $value): string
    {
        return '`'.str_replace('`', '``', $value).'`';
    }

    private function quoteString(#[SensitiveParameter] string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }
}
