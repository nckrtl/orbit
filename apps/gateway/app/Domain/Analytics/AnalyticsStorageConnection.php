<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\DatabaseConnections\DockerPublishedPort;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Process;
use SensitiveParameter;

/**
 * The two URLs Plausible connects with, derived from the environment the PostgreSQL and ClickHouse
 * Processes already carry. Orbit provisions nothing inside either server: the ClickHouse image
 * creates its own database and user, and Plausible creates and migrates its PostgreSQL database
 * each time it starts.
 */
final readonly class AnalyticsStorageConnection
{
    /** The database `plausible db createdb` creates in PostgreSQL. */
    public const string POSTGRES_DATABASE = 'plausible_db';

    private function __construct(
        #[SensitiveParameter]
        public string $databaseUrl,
        #[SensitiveParameter]
        public string $clickhouseDatabaseUrl,
    ) {}

    public static function from(
        #[SensitiveParameter]
        Process $postgres,
        #[SensitiveParameter]
        Process $clickhouse,
    ): self {
        return new self(
            databaseUrl: sprintf(
                'postgres://%s:%s@%s:%d/%s',
                rawurlencode(self::optional($postgres, 'POSTGRES_USER') ?? 'postgres'),
                rawurlencode(self::required($postgres, 'POSTGRES_PASSWORD')),
                self::host($postgres),
                self::publishedPort($postgres, 5432),
                rawurlencode(self::POSTGRES_DATABASE),
            ),
            clickhouseDatabaseUrl: sprintf(
                'http://%s:%s@%s:%d/%s',
                rawurlencode(self::required($clickhouse, 'CLICKHOUSE_USER')),
                rawurlencode(self::required($clickhouse, 'CLICKHOUSE_PASSWORD')),
                self::host($clickhouse),
                self::publishedPort($clickhouse, 8123),
                rawurlencode(self::required($clickhouse, 'CLICKHOUSE_DB')),
            ),
        );
    }

    /** @return array{} */
    public function __debugInfo(): array
    {
        return [];
    }

    private static function required(#[SensitiveParameter] Process $process, string $key): string
    {
        return self::optional($process, $key) ?? throw new ResourceOperationException(
            errorCode: 'analytics.storage_credentials_missing',
            message: "Process [{$process->name}] has no {$key} in its environment.",
            status: 422,
        );
    }

    private static function optional(#[SensitiveParameter] Process $process, string $key): ?string
    {
        $value = $process->runtime_config['environment'][$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function host(#[SensitiveParameter] Process $process): string
    {
        $process->loadMissing('owner');
        $node = $process->owner;
        $host = $node instanceof Node ? $node->wireguard_ip : null;

        if (! is_string($host) || $host === '') {
            throw new ResourceOperationException(
                errorCode: 'process.wireguard_ip_missing',
                message: "The Node of Process [{$process->name}] has no WireGuard address.",
                status: 422,
            );
        }

        return $host;
    }

    private static function publishedPort(#[SensitiveParameter] Process $process, int $containerPort): int
    {
        $ports = $process->runtime_config['ports'] ?? [];

        foreach (is_array($ports) ? $ports : [] as $spec) {
            $mapping = is_string($spec) ? DockerPublishedPort::parse($spec) : null;

            if ($mapping instanceof DockerPublishedPort && $mapping->containerPort === $containerPort) {
                return $mapping->publishedPort;
            }
        }

        throw new ResourceOperationException(
            errorCode: 'analytics.storage_port_missing',
            message: "Process [{$process->name}] does not publish port {$containerPort}.",
            status: 422,
        );
    }
}
