<?php

declare(strict_types=1);

namespace App\Services\Database;

use PDO;
use Pdo\Sqlite;
use PDOException;

final readonly class DynamicPdoConnection
{
    /**
     * Open a one-shot local SQLite connection.
     *
     * @param  array{driver: string, path?: string, username?: string|null, password?: string|null}  $config
     */
    public function connect(array $config, bool $write): PDO
    {
        try {
            $pdo = new PDO(
                $this->dsn($config),
                $config['username'] ?? null,
                $config['password'] ?? null,
                $this->options($config['driver'], $write),
            );
        } catch (PDOException) {
            $this->fail();
        }

        return $pdo;
    }

    /**
     * @param  array{driver: string, path?: string}  $config
     */
    public function dsn(array $config): string
    {
        return match ($config['driver']) {
            'sqlite' => 'sqlite:'.($config['path'] ?? ''),
            default => throw new LocalDatabaseQueryException(
                'database.query_failed',
                'Database query failed.',
            ),
        };
    }

    /**
     * @return array<int, mixed>
     */
    private function options(string $driver, bool $write): array
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 10,
        ];

        if ($driver === 'sqlite' && ! $write) {
            $options[Sqlite::ATTR_OPEN_FLAGS] = Sqlite::OPEN_READONLY;
        }

        return $options;
    }

    private function fail(): never
    {
        throw new LocalDatabaseQueryException(
            'database.query_failed',
            'Database query failed.',
        );
    }
}
