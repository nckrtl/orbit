<?php

declare(strict_types=1);

namespace App\Domain\DatabaseConnections;

enum DatabaseDriver: string
{
    case Mysql = 'mysql';
    case Pgsql = 'pgsql';
    case Sqlite = 'sqlite';

    public function defaultPort(): ?int
    {
        return match ($this) {
            self::Mysql => 3306,
            self::Pgsql => 5432,
            self::Sqlite => null,
        };
    }

    public function usesNetworkEndpoint(): bool
    {
        return $this !== self::Sqlite;
    }
}
