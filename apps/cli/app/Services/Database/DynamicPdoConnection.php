<?php

declare(strict_types=1);

namespace App\Services\Database;

use PDO;
use Pdo\Sqlite;
use PDOException;
use ReflectionClass;

final readonly class DynamicPdoConnection
{
    /**
     * Open a one-shot PDO connection from a Laravel-shaped config array.
     *
     * @param  array{driver: string, path?: string, host?: string, port?: int, database?: string, username?: string|null, password?: string|null}  $config
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
     * @param  array{driver: string, path?: string, host?: string, port?: int, database?: string}  $config
     */
    public function dsn(array $config): string
    {
        return match ($config['driver']) {
            'mysql' => sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $config['host'] ?? '',
                $config['port'] ?? 3306,
                $config['database'] ?? '',
            ),
            'pgsql' => sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $config['host'] ?? '',
                $config['port'] ?? 5432,
                $config['database'] ?? '',
            ),
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
            if (defined(Sqlite::class.'::ATTR_OPEN_FLAGS') && defined(Sqlite::class.'::OPEN_READONLY')) {
                $options[Sqlite::ATTR_OPEN_FLAGS] = Sqlite::OPEN_READONLY;
            } else {
                $pdo = new ReflectionClass(PDO::class);

                if (! $pdo->hasConstant('SQLITE_ATTR_OPEN_FLAGS') || ! $pdo->hasConstant('SQLITE_OPEN_READONLY')) {
                    $this->fail();
                }

                $attribute = $pdo->getConstant('SQLITE_ATTR_OPEN_FLAGS');
                $readOnly = $pdo->getConstant('SQLITE_OPEN_READONLY');

                if (! is_int($attribute) || ! is_int($readOnly)) {
                    $this->fail();
                }

                $options[$attribute] = $readOnly;
            }
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
