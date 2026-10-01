<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Shared\ResourceOperationException;
use SensitiveParameter;

/**
 * Renders the SQL that Orbit runs as root on a Database server. Every name is validated against a
 * narrow identifier pattern before it is quoted, and every password must be a single line.
 */
final readonly class MysqlStatements
{
    public const string USERNAME_PATTERN = '/\A[A-Za-z_][A-Za-z0-9_]{0,31}\z/D';

    private const string DATABASE_NAME_PATTERN = '/\A[A-Za-z0-9_]{1,64}\z/D';

    private const string USER_NAME_PATTERN = '/\A[A-Za-z0-9_]{1,32}\z/D';

    public function ensureUser(string $username, #[SensitiveParameter] string $password): string
    {
        $user = $this->account($username);
        $secret = $this->password($password);

        return $this->lines([
            "CREATE USER IF NOT EXISTS {$user} IDENTIFIED BY {$secret};",
            "ALTER USER {$user} IDENTIFIED BY {$secret};",
        ]);
    }

    public function createDatabase(string $database): string
    {
        return $this->lines(['CREATE DATABASE '.$this->database($database).';']);
    }

    public function grantAll(string $database, string $username): string
    {
        return $this->lines([
            'GRANT ALL PRIVILEGES ON '.$this->database($database).'.* TO '.$this->account($username).';',
        ]);
    }

    /** Grant every privilege on each database whose name starts with `$prefix`. */
    public function grantAllWithPrefix(string $prefix, string $username): string
    {
        return $this->lines([
            'GRANT ALL PRIVILEGES ON '.$this->databasePattern($prefix).'.* TO '.$this->account($username).';',
        ]);
    }

    /** Replace the user's privileges on one database. The first grant makes the revoke safe for a new user. */
    public function replaceGrant(string $database, string $username, bool $readOnly): string
    {
        $quoted = $this->database($database);
        $user = $this->account($username);
        $privileges = $readOnly ? 'SELECT' : 'ALL PRIVILEGES';

        return $this->lines([
            "GRANT SELECT ON {$quoted}.* TO {$user};",
            "REVOKE ALL PRIVILEGES ON {$quoted}.* FROM {$user};",
            "GRANT {$privileges} ON {$quoted}.* TO {$user};",
        ]);
    }

    public function dropDatabase(string $database): string
    {
        return $this->lines(['DROP DATABASE IF EXISTS '.$this->database($database).';']);
    }

    public function dropUser(string $username): string
    {
        return $this->lines(['DROP USER IF EXISTS '.$this->account($username).';']);
    }

    /** List the existing databases named `$database`, plus those that start with `$prefix` when given. */
    public function databasesNamed(string $database, ?string $prefix = null): string
    {
        $condition = 'SCHEMA_NAME = '.$this->string($this->checkedDatabase($database));

        if ($prefix !== null) {
            $condition .= ' OR SCHEMA_NAME LIKE '.$this->string($this->likePrefix($prefix));
        }

        return $this->lines([
            "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE {$condition} ORDER BY SCHEMA_NAME;",
        ]);
    }

    public function userNamed(string $username): string
    {
        return $this->lines([
            'SELECT User FROM mysql.user WHERE User = '.$this->string($this->checkedUser($username)).';',
        ]);
    }

    /** The privilege text that Orbit records for a user, for example ALL PRIVILEGES ON `app`.*. */
    public function privilegeText(string $database, bool $readOnly = false, ?string $prefix = null): string
    {
        $text = ($readOnly ? 'SELECT' : 'ALL PRIVILEGES').' ON '.$this->database($database).'.*';

        if ($prefix !== null) {
            $text .= ', '.$this->databasePattern($prefix).'.*';
        }

        return $text;
    }

    public function likePrefix(string $prefix): string
    {
        return str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $this->checkedDatabase($prefix)).'%';
    }

    /** @return array{} */
    public function __debugInfo(): array
    {
        return [];
    }

    private function database(string $database): string
    {
        return '`'.$this->checkedDatabase($database).'`';
    }

    private function databasePattern(string $prefix): string
    {
        return '`'.$this->likePrefix($prefix).'`';
    }

    private function account(string $username): string
    {
        return $this->string($this->checkedUser($username))."@'%'";
    }

    private function password(#[SensitiveParameter] string $password): string
    {
        if ($password === '' || preg_match('/[\x00\r\n]/', $password) === 1) {
            throw new ResourceOperationException(
                errorCode: 'validation.failed',
                message: 'A MySQL password must be a non-empty single line.',
                status: 422,
            );
        }

        return $this->string($password);
    }

    private function checkedDatabase(string $database): string
    {
        if (preg_match(self::DATABASE_NAME_PATTERN, $database) !== 1) {
            throw new ResourceOperationException(
                errorCode: 'validation.failed',
                message: 'A MySQL database name must be 1-64 letters, digits, or underscores.',
                status: 422,
            );
        }

        return $database;
    }

    private function checkedUser(string $username): string
    {
        if (preg_match(self::USER_NAME_PATTERN, $username) !== 1) {
            throw new ResourceOperationException(
                errorCode: 'validation.failed',
                message: 'A MySQL user name must be 1-32 letters, digits, or underscores.',
                status: 422,
            );
        }

        return $username;
    }

    private function string(#[SensitiveParameter] string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }

    /** @param list<string> $lines */
    private function lines(#[SensitiveParameter] array $lines): string
    {
        return implode("\n", $lines)."\n";
    }
}
