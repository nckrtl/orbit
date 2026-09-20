<?php

declare(strict_types=1);

namespace App\Domain\ProxyCli;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\Node;

final readonly class ProxyCliPlacement
{
    public function connection(string $slug): DatabaseConnection
    {
        $connection = DatabaseConnection::query()->where('slug', $slug)->first();

        if (! $connection instanceof DatabaseConnection) {
            throw new ResourceOperationException(
                'proxycli.cache_missing',
                'Enable requires a registered Redis Database connection for shared Valkey.',
                422,
            );
        }

        if ($connection->driver !== DatabaseDriver::Redis) {
            throw new ResourceOperationException(
                'proxycli.cache_invalid',
                'The proxycli cache connection must use the redis driver.',
                422,
            );
        }

        if ($connection->node_id === null) {
            return $connection;
        }

        $node = Node::query()->find($connection->node_id);

        if (! $node instanceof Node) {
            throw new ResourceOperationException(
                'proxycli.cache_unplaced',
                'The proxycli cache connection names a Node that is not in the fleet.',
                422,
            );
        }

        $hasDatabase = $node->roles()
            ->where('role', RoleName::Database->value)
            ->where('status', LifecycleStatus::Active->value)
            ->exists();

        if (! $hasDatabase) {
            throw new ResourceOperationException(
                'proxycli.cache_unplaced',
                'Shared Valkey must run on a Node with the active database role.',
                422,
            );
        }

        return $connection;
    }

    public function node(int $nodeId): Node
    {
        $node = Node::query()->find($nodeId);

        if (
            ! $node instanceof Node
            || $node->status !== LifecycleStatus::Active
            || ! is_string($node->wireguard_ip)
            || $node->wireguard_ip === ''
            || ($node->platform !== null && $node->platform !== 'linux')
        ) {
            throw new ResourceOperationException(
                'proxycli.node_invalid',
                'proxycli enable requires an active Linux Node with a WireGuard address.',
                422,
            );
        }

        return $node;
    }
}
