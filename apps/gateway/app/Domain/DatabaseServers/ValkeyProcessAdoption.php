<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseConnections\DockerPublishedPort;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Process;

/**
 * The checks before `database:server:create --process --connection` adopts an existing Valkey Process (ADR 0221). A
 * failed check refuses with `database.server_adoption_refused`, names the check, and changes nothing. The option exists
 * only to adopt the Valkey Processes on `services` and `beast`.
 */
final readonly class ValkeyProcessAdoption
{
    public function __construct(
        private ValkeyServerAdmin $valkey,
    ) {}

    /**
     * Check the Process and the connection, and log in with the connection's password as `default`.
     *
     * @return array{process: Process, connection: DatabaseConnection, port: int}
     */
    public function check(Node $node, string $slug, int $processId, string $connectionSlug): array
    {
        $process = Process::query()->find($processId);

        if (
            ! $process instanceof Process
            || $process->owner_type !== Node::class
            || $process->owner_id !== $node->id
            || $process->runtime !== ProcessRuntime::Docker
        ) {
            throw self::refused("Process [{$processId}] is not a Docker Process of Node [{$node->name}].");
        }

        $image = $process->runtime_config['image'] ?? null;

        if (! is_string($image) || preg_match('/\Avalkey\/valkey(?:[:@][^\s]+)?\z/D', $image) !== 1) {
            throw self::refused("Process [{$process->name}] does not run a valkey/valkey image.");
        }

        if (DatabaseServerProcess::valkeyVolume($process) === null) {
            throw self::refused("Process [{$process->name}] has no Docker volume at /data.");
        }

        $port = $this->publishedPort($process, $node);

        if ($port === null) {
            throw self::refused("Process [{$process->name}] does not publish container port 6379 on the WireGuard address of Node [{$node->name}].");
        }

        self::assertUnowned($process);

        $connection = DatabaseConnection::query()->where('slug', $connectionSlug)->first();

        if (! $connection instanceof DatabaseConnection || $connection->driver !== DatabaseDriver::Redis) {
            throw self::refused("Database connection [{$connectionSlug}] is not a redis connection.");
        }

        if ($connection->host !== $node->wireguard_ip || ($connection->port ?? DatabaseDriver::Redis->defaultPort()) !== $port) {
            throw self::refused("Database connection [{$connectionSlug}] does not point to {$node->wireguard_ip}:{$port}.");
        }

        // Its clients keep logging in as `default` after the adoption, the only user in the new ACL file.
        $candidate = new DatabaseServer(['slug' => $slug, 'port' => $port, 'root_password' => $connection->password]);
        $candidate->setRelation('node', $node);

        if (($connection->username !== null && $connection->username !== 'default') || ! (filled($connection->password) ? $this->valkey->acceptsAdminLogin($candidate) : $this->valkey->acceptsAnonymous($candidate))) {
            throw self::refused("The password of Database connection [{$connectionSlug}] does not log in as default.");
        }

        if (array_diff(array_keys($this->valkey->aclUsers($candidate)), ['default']) !== []) {
            throw self::refused('The Process already has non-default ACL users. Adoption would remove them.');
        }

        return ['process' => $process, 'connection' => $connection, 'port' => $port];
    }

    /** The connection to link when an adoption finishes: the redis connection that holds the admin password. */
    public function connection(DatabaseServer $server, string $connectionSlug): DatabaseConnection
    {
        $connection = DatabaseConnection::query()->where('slug', $connectionSlug)->first();

        if (
            ! $connection instanceof DatabaseConnection
            || $connection->driver !== DatabaseDriver::Redis
            || ($server->default_open ? filled($connection->password) : $connection->password !== $server->root_password)
            || $connection->host !== $server->node->wireguard_ip
            || ($connection->port ?? 6379) !== $server->port
            || ! in_array($connection->username, [null, 'default'], true)
            || ($connection->database_server_id !== null && $connection->database_server_id !== $server->id)
        ) {
            throw self::refused("Database connection [{$connectionSlug}] does not hold the admin password of Database server [{$server->slug}].");
        }

        return $connection;
    }

    public static function assertUnowned(Process $process): void
    {
        $owner = DatabaseServer::query()->where('process_id', $process->id)->first();

        if ($owner instanceof DatabaseServer) {
            throw self::refused("Process [{$process->name}] already runs Database server [{$owner->slug}].");
        }
    }

    public static function refused(string $message): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'database.server_adoption_refused',
            message: $message,
            status: 409,
        );
    }

    /** The host port that publishes container port 6379 on the Node's WireGuard address. */
    private function publishedPort(Process $process, Node $node): ?int
    {
        $ports = $process->runtime_config['ports'] ?? null;

        foreach (is_array($ports) ? $ports : [] as $spec) {
            $mapping = is_string($spec) && ! str_ends_with($spec, '/udp') ? DockerPublishedPort::parse($spec) : null;

            if (
                $mapping instanceof DockerPublishedPort
                && $mapping->bindAddress === $node->wireguard_ip
                && $mapping->containerPort === DatabaseServerProcess::VALKEY_CONTAINER_PORT
            ) {
                return $mapping->publishedPort;
            }
        }

        return null;
    }
}
