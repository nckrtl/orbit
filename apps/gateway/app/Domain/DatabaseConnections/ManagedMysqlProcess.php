<?php

declare(strict_types=1);

namespace App\Domain\DatabaseConnections;

use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Process;
use SensitiveParameter;

final readonly class ManagedMysqlProcess
{
    public const string IMAGE_PATTERN = '/(?:^|\/)(?:mysql|mysql-server)(?::|@|$)/';

    public function __construct(
        public Process $process,
        public Node $node,
        public string $host,
        public int $port,
        #[SensitiveParameter]
        public string $rootPassword,
    ) {}

    public static function from(#[SensitiveParameter] Process $process): self
    {
        if ($process->owner_type !== Node::class) {
            throw new ResourceOperationException(
                errorCode: 'database.process_not_node',
                message: 'A managed MySQL user requires a Node-targeted Process.',
                status: 422,
            );
        }

        if ($process->runtime !== ProcessRuntime::Docker) {
            throw new ResourceOperationException(
                errorCode: 'database.process_not_docker',
                message: "Process [{$process->name}] is not a Docker Process.",
                status: 422,
            );
        }

        $process->loadMissing('owner');
        $node = $process->owner;

        if (! $node instanceof Node) {
            throw new ResourceOperationException(
                errorCode: 'database.process_not_node',
                message: 'A managed MySQL user requires a Node-targeted Process.',
                status: 422,
            );
        }

        if ($node->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException(
                errorCode: 'process.target_inactive',
                message: "Node [{$node->name}] is not active.",
                status: 422,
            );
        }

        $host = $node->wireguard_ip;

        if (! is_string($host) || $host === '') {
            throw new ResourceOperationException(
                errorCode: 'process.wireguard_ip_missing',
                message: "Node [{$node->name}] has no WireGuard address.",
                status: 422,
            );
        }

        $image = $process->runtime_config['image'] ?? null;

        if (! is_string($image) || preg_match(self::IMAGE_PATTERN, $image) !== 1) {
            throw new ResourceOperationException(
                errorCode: 'database.process_not_mysql',
                message: "Process [{$process->name}] is not a Docker MySQL Process.",
                status: 422,
            );
        }

        $port = self::publishedMysqlPort($process);

        if ($port === null) {
            throw new ResourceOperationException(
                errorCode: 'database.process_not_mysql',
                message: "Process [{$process->name}] does not publish MySQL port 3306.",
                status: 422,
            );
        }

        $rootPassword = $process->runtime_config['environment']['MYSQL_ROOT_PASSWORD'] ?? null;

        if (! is_string($rootPassword) || $rootPassword === '') {
            throw new ResourceOperationException(
                errorCode: 'database.root_password_missing',
                message: "Process [{$process->name}] has no MYSQL_ROOT_PASSWORD.",
                status: 422,
            );
        }

        return new self(
            process: $process,
            node: $node,
            host: $host,
            port: $port,
            rootPassword: $rootPassword,
        );
    }

    /** @return array{host: string, port: int} */
    public function __debugInfo(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
        ];
    }

    private static function publishedMysqlPort(#[SensitiveParameter] Process $process): ?int
    {
        $ports = $process->runtime_config['ports'] ?? [];

        if (! is_array($ports)) {
            return null;
        }

        foreach ($ports as $spec) {
            if (! is_string($spec)) {
                continue;
            }

            $mapping = DockerPublishedPort::parse($spec);

            if ($mapping instanceof DockerPublishedPort && $mapping->containerPort === 3306) {
                return $mapping->publishedPort;
            }
        }

        return null;
    }
}
