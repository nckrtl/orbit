<?php

declare(strict_types=1);

namespace App\Domain\DatabaseConnections;

enum DatabaseDriver: string
{
    case Mysql = 'mysql';
    case Pgsql = 'pgsql';
    case Sqlite = 'sqlite';
    case Redis = 'redis';

    public function defaultPort(): ?int
    {
        return match ($this) {
            self::Mysql => 3306,
            self::Pgsql => 5432,
            self::Redis => 6379,
            self::Sqlite => null,
        };
    }

    public function usesNetworkEndpoint(): bool
    {
        return $this !== self::Sqlite;
    }

    /**
     * Whether this driver requires a stored database name, username, and password,
     * as mysql and pgsql do. Redis requires only a host; its database index,
     * username, and password stay optional and are stored only when given.
     */
    public function requiresCredentials(): bool
    {
        return $this === self::Mysql || $this === self::Pgsql;
    }

    /**
     * Whether the Gateway can run query, tables, schema, and describe against
     * this driver. Redis is not a relational store and has no PDO inspector.
     */
    public function supportsInspection(): bool
    {
        return $this !== self::Redis;
    }
}
