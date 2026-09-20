<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\Process;

/** An active Node that holds the active `database` role. */
function analytics_database_node(string $name = 'analytics-db', string $wireguardIp = '10.44.0.200'): Node
{
    $node = Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.200',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => $wireguardIp,
    ]);
    $node->roles()->create(['role' => RoleName::Database, 'status' => LifecycleStatus::Active]);

    return $node;
}

/** @param array<string, mixed> $attributes */
function analytics_storage_process(Node $node, string $name, string $image, array $attributes = []): Process
{
    return Process::query()->create([
        'owner_type' => Node::class,
        'owner_id' => $node->id,
        'name' => $name,
        'runtime' => ProcessRuntime::Docker,
        'working_directory' => '/app',
        'runtime_config' => [
            'image' => $image,
            'command' => [],
            'environment' => [],
            'ports' => [],
            'volumes' => [],
        ],
        'restart_policy' => 'unless-stopped',
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Active,
        ...$attributes,
    ]);
}

/**
 * The storage the analytics role expects: a PostgreSQL 16 Process and a
 * ClickHouse Process on one active database Node.
 *
 * @return array{node: Node, postgres: Process, clickhouse: Process}
 */
function analytics_storage_processes(): array
{
    $node = analytics_database_node();

    return [
        'node' => $node,
        'postgres' => analytics_connection_process(
            $node,
            'plausible-postgres',
            'postgres:16-alpine',
            ['POSTGRES_PASSWORD' => 'postgres-secret'],
            ["{$node->wireguard_ip}:5432:5432/tcp"],
        ),
        'clickhouse' => analytics_connection_process(
            $node,
            'plausible-clickhouse',
            'clickhouse/clickhouse-server:24.12-alpine',
            ['CLICKHOUSE_USER' => 'plausible', 'CLICKHOUSE_PASSWORD' => 'clickhouse-secret', 'CLICKHOUSE_DB' => 'plausible_events_db'],
            ["{$node->wireguard_ip}:8123:8123/tcp"],
        ),
    ];
}
