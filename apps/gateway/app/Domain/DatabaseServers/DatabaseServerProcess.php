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
 * for the first start and removed afterwards.
 */
final readonly class DatabaseServerProcess
{
    public const string ROOT_PASSWORD_VARIABLE = 'MYSQL_ROOT_PASSWORD';

    public const int CONTAINER_PORT = 3306;

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

    public static function publishedPort(Node $node, int $port): string
    {
        return "{$node->wireguard_ip}:{$port}:".self::CONTAINER_PORT;
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
