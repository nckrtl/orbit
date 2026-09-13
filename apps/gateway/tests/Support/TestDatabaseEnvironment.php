<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

final class TestDatabaseEnvironment
{
    public const AllocatedDatabaseVariable = 'ORBIT_TEST_DATABASE';

    public const AllocatedDatabasePrefix = 'orbit-gateway-test-';

    public static function bootstrap(): void
    {
        self::set('APP_ENV', 'testing');
        self::set('DB_CONNECTION', 'sqlite');
        self::set('DB_DATABASE', self::allocatedDatabase() ?? ':memory:');
        self::set('DB_URL', '');
    }

    public static function allocatedDatabase(): ?string
    {
        $database = getenv(self::AllocatedDatabaseVariable);

        if ($database === false || $database === '') {
            return null;
        }

        if (! str_starts_with($database, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException(
                self::AllocatedDatabaseVariable.' must be an absolute path in the system temporary directory.',
            );
        }

        $temporaryDirectory = realpath(sys_get_temp_dir());
        $databaseDirectory = realpath(dirname($database));

        if (
            $temporaryDirectory === false
            || $databaseDirectory === false
            || ($databaseDirectory !== $temporaryDirectory
                && ! str_starts_with($databaseDirectory, $temporaryDirectory.DIRECTORY_SEPARATOR))
            || ! str_starts_with(basename($database), self::AllocatedDatabasePrefix)
        ) {
            throw new RuntimeException(
                self::AllocatedDatabaseVariable.' must name an orbit-gateway-test-* file in the system temporary directory.',
            );
        }

        $resolvedDatabase = realpath($database);

        if ($resolvedDatabase !== false && $resolvedDatabase !== $databaseDirectory.DIRECTORY_SEPARATOR.basename($database)) {
            throw new RuntimeException(self::AllocatedDatabaseVariable.' must not be a symbolic link.');
        }

        return $database;
    }

    private static function set(string $name, string $value): void
    {
        if (! putenv("{$name}={$value}")) {
            throw new RuntimeException("Could not set the {$name} test environment variable.");
        }

        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
