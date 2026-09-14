<?php

declare(strict_types=1);

namespace App\Domain\DatabaseConnections;

use App\Domain\Processes\ProcessRuntime;
use App\Models\AppInstance;
use App\Models\DatabaseConnection;
use App\Models\Node;
use App\Models\Process;

final readonly class DatabaseConnectionEnvProjection
{
    /** @var list<string> */
    public const array Suffixes = [
        'CONNECTION',
        'HOST',
        'PORT',
        'DATABASE',
        'USERNAME',
        'PASSWORD',
    ];

    /**
     * @return array{
     *     values: array<string, string>,
     *     forget: list<string>,
     *     keys: list<string>,
     *     host: string|null,
     *     port: int|null
     * }
     */
    public function project(
        DatabaseConnection $connection,
        AppInstance $instance,
        string $prefix,
    ): array {
        $managed = $this->managedKeys($prefix);
        $values = [$this->key($prefix, 'CONNECTION') => $connection->driver->value];

        if ($connection->driver === DatabaseDriver::Sqlite) {
            $values[$this->key($prefix, 'DATABASE')] = (string) $connection->path;

            if (is_string($connection->username)) {
                $values[$this->key($prefix, 'USERNAME')] = $connection->username;
            }

            if (is_string($connection->password)) {
                $values[$this->key($prefix, 'PASSWORD')] = $connection->password;
            }

            return $this->result($values, $managed, host: null, port: null);
        }

        [$host, $port] = $this->resolveEndpoint($connection, $instance);
        $values[$this->key($prefix, 'HOST')] = $host;
        $values[$this->key($prefix, 'PORT')] = (string) $port;
        $values[$this->key($prefix, 'DATABASE')] = (string) $connection->database;
        $values[$this->key($prefix, 'USERNAME')] = (string) $connection->username;
        $values[$this->key($prefix, 'PASSWORD')] = (string) $connection->password;

        return $this->result($values, $managed, $host, $port);
    }

    /** @return list<string> */
    public function managedKeys(string $prefix): array
    {
        return array_map(
            fn (string $suffix): string => $this->key($prefix, $suffix),
            self::Suffixes,
        );
    }

    public function key(string $prefix, string $suffix): string
    {
        return $prefix.'_'.$suffix;
    }

    /**
     * @param  array<string, string>  $values
     * @param  list<string>  $managed
     * @return array{
     *     values: array<string, string>,
     *     forget: list<string>,
     *     keys: list<string>,
     *     host: string|null,
     *     port: int|null
     * }
     */
    private function result(array $values, array $managed, ?string $host, ?int $port): array
    {
        $keys = array_keys($values);
        sort($keys);

        return [
            'values' => $values,
            'forget' => array_values(array_diff($managed, $keys)),
            'keys' => $keys,
            'host' => $host,
            'port' => $port,
        ];
    }

    /** @return array{string, int} */
    private function resolveEndpoint(DatabaseConnection $connection, AppInstance $instance): array
    {
        $host = (string) $connection->host;
        $port = (int) $connection->port;
        $published = $this->alignedPublishedPort($connection);

        if (
            $published instanceof DockerPublishedPort
            && $connection->node_id !== null
            && $instance->node_id === $connection->node_id
        ) {
            return ['127.0.0.1', $published->publishedPort];
        }

        return [$host, $port];
    }

    private function alignedPublishedPort(DatabaseConnection $connection): ?DockerPublishedPort
    {
        if ($connection->node_id === null || $connection->port === null) {
            return null;
        }

        $processes = Process::query()
            ->where('owner_type', Node::class)
            ->where('owner_id', $connection->node_id)
            ->where('runtime', ProcessRuntime::Docker)
            ->orderBy('id')
            ->get();

        foreach ($processes as $process) {
            $ports = $process->runtime_config['ports'] ?? [];

            if (! is_array($ports)) {
                continue;
            }

            foreach ($ports as $spec) {
                if (! is_string($spec)) {
                    continue;
                }

                $mapping = DockerPublishedPort::parse($spec);

                if ($mapping instanceof DockerPublishedPort && $mapping->matches($connection->port)) {
                    return $mapping;
                }
            }
        }

        return null;
    }
}
