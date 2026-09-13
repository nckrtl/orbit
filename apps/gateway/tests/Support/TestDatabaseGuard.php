<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\ConfigurationUrlParser;
use RuntimeException;
use Throwable;

final class TestDatabaseGuard
{
    public static function register(Application $app): void
    {
        $app->afterBootstrapping(
            LoadConfiguration::class,
            static function (Application $application): void {
                self::assertSafe($application);
            },
        );
    }

    public static function assertSafe(Application $app): void
    {
        if (! $app->environment('testing')) {
            self::refuse('the application environment is not testing');
        }

        $connectionName = $app->make('config')->get('database.default');

        if (! is_string($connectionName) || $connectionName === '') {
            self::refuse('the default connection is missing');
        }

        $connection = $app->make('config')->get("database.connections.{$connectionName}");

        if (! is_array($connection)) {
            self::refuse('the default connection configuration is missing');
        }

        try {
            $effective = (new ConfigurationUrlParser)->parseConfiguration($connection);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Gateway tests refused an unsafe database connection because its URL is invalid.',
                previous: $exception,
            );
        }

        foreach (self::effectiveConnections($effective) as $effectiveConnection) {
            self::assertEffectiveConnectionSafe($effectiveConnection);
        }
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return list<array<string, mixed>>
     */
    private static function effectiveConnections(array $connection): array
    {
        if (! isset($connection['read'])) {
            return [$connection];
        }

        return [
            ...self::mergedConnections($connection, 'write'),
            ...self::mergedConnections($connection, 'read'),
        ];
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return list<array<string, mixed>>
     */
    private static function mergedConnections(array $connection, string $type): array
    {
        $configured = $connection[$type] ?? null;

        if (! is_array($configured)) {
            self::refuse("the effective {$type} connection configuration is invalid");
        }

        $overrides = isset($configured[0]) ? $configured : [$configured];
        $connections = [];

        foreach ($overrides as $override) {
            if (! is_array($override)) {
                self::refuse("the effective {$type} connection configuration is invalid");
            }

            $effective = array_merge($connection, $override);
            unset($effective['read'], $effective['write']);
            $connections[] = $effective;
        }

        return $connections;
    }

    /** @param array<string, mixed> $effective */
    private static function assertEffectiveConnectionSafe(array $effective): void
    {
        if (($effective['driver'] ?? null) !== 'sqlite') {
            self::refuse('the effective driver is not SQLite');
        }

        $database = $effective['database'] ?? null;

        if ($database === ':memory:') {
            return;
        }

        $allocatedDatabase = TestDatabaseEnvironment::allocatedDatabase();

        if (is_string($database) && $allocatedDatabase !== null && $database === $allocatedDatabase) {
            return;
        }

        self::refuse('the effective database is not an approved disposable test database');
    }

    private static function refuse(string $reason): never
    {
        throw new RuntimeException(
            "Gateway tests refused an unsafe database connection: {$reason}. "
            .'Use in-memory SQLite or set ORBIT_TEST_DATABASE to an orbit-gateway-test-* file in the system temporary directory.',
        );
    }
}
