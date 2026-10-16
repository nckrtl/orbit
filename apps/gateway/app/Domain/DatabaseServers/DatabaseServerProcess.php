<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Data\Processes\AddProcessData;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessTargetType;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Process;
use SensitiveParameter;

/**
 * The Docker Node Process that runs a Database server. The MySQL image reads
 * MYSQL_ROOT_PASSWORD only when it initializes an empty data directory, so the variable is set
 * for the first start and removed afterwards. Valkey has no environment: it starts only from the
 * ACL file that Orbit writes into its volume, which holds the hash of the admin password.
 */
final readonly class DatabaseServerProcess
{
    public const string ROOT_PASSWORD_VARIABLE = 'MYSQL_ROOT_PASSWORD';

    public const int CONTAINER_PORT = 3306;

    public const int VALKEY_CONTAINER_PORT = 6379;

    public const string VALKEY_DATA_DIRECTORY = '/data';

    public const string VALKEY_ACL_FILE = '/data/users.acl';

    public static function data(
        DatabaseServer $server,
        Node $node,
        #[SensitiveParameter]
        string $rootPassword,
    ): AddProcessData {
        return new AddProcessData(
            targetType: ProcessTargetType::Node,
            targetId: $node->id,
            name: $server->slug,
            runtime: ProcessRuntime::Docker,
            command: ['mysqld'],
            image: 'mysql:'.$server->tag,
            workingDirectory: null,
            environment: [self::ROOT_PASSWORD_VARIABLE => $rootPassword],
            // WireGuard only: Docker publishes around the host firewall, so a server never
            // listens on a public address.
            ports: [self::publishedPort($node, $server->port)],
            volumes: [[
                'source' => self::volume($server->slug),
                'target' => '/var/lib/mysql',
                'read_only' => false,
            ]],
            restartPolicy: 'unless-stopped',
            start: true,
        );
    }

    /**
     * The Valkey server Process. A created server's Process is named after the slug and uses `orbit-<slug>-data`; an
     * adopted Process keeps its own name and its volume at /data.
     */
    public static function valkeyData(DatabaseServer $server, Node $node, string $name, string $volume): AddProcessData
    {
        return new AddProcessData(
            targetType: ProcessTargetType::Node,
            targetId: $node->id,
            name: $name,
            runtime: ProcessRuntime::Docker,
            command: ['valkey-server', '--appendonly', 'yes', '--dir', self::VALKEY_DATA_DIRECTORY, '--aclfile', self::VALKEY_ACL_FILE],
            image: self::valkeyImage($server->tag),
            workingDirectory: self::VALKEY_DATA_DIRECTORY,
            environment: [],
            ports: [self::publishedPort($node, $server->port, self::VALKEY_CONTAINER_PORT)],
            volumes: [[
                'source' => $volume,
                'target' => self::VALKEY_DATA_DIRECTORY,
                'read_only' => false,
            ]],
            restartPolicy: 'unless-stopped',
            start: true,
        );
    }

    public static function valkeyImage(string $tag): string
    {
        return 'valkey/valkey:'.$tag;
    }

    /**
     * The ACL file that a Valkey server starts from: `default`, the admin user, with the hash of the admin password and
     * every right. Explicit adoption can preserve an open default until the operator closes it.
     */
    public static function valkeyAclFile(#[SensitiveParameter] string $adminPassword, bool $defaultOpen = false): string
    {
        return 'user default on '.($defaultOpen ? 'nopass' : '#'.ValkeyAclUser::passwordHash($adminPassword))." ~* &* +@all\n";
    }

    /** The Docker volume that a Process mounts at /data, or null when it mounts none there or a host path. */
    public static function valkeyVolume(#[SensitiveParameter] Process $process): ?string
    {
        $volumes = $process->runtime_config['volumes'] ?? null;

        foreach (is_array($volumes) ? $volumes : [] as $volume) {
            $source = is_array($volume) ? ($volume['source'] ?? null) : null;

            if (
                is_array($volume)
                && ($volume['target'] ?? null) === self::VALKEY_DATA_DIRECTORY
                && is_string($source)
                && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{1,254}\z/D', $source) === 1
            ) {
                return $source;
            }
        }

        return null;
    }

    public static function publishedPort(Node $node, int $port, int $containerPort = self::CONTAINER_PORT): string
    {
        return "{$node->wireguard_ip}:{$port}:{$containerPort}";
    }

    public static function volume(string $slug): string
    {
        return "orbit-{$slug}-data";
    }

    public static function holdsRootPassword(#[SensitiveParameter] Process $process): bool
    {
        $environment = $process->runtime_config['environment'] ?? null;

        return is_array($environment) && array_key_exists(self::ROOT_PASSWORD_VARIABLE, $environment);
    }
}
