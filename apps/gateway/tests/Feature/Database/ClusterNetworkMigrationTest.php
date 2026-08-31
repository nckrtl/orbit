<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

describe('cluster and node network migration', function (): void {
    it('preserves legacy node state and copies every WireGuard address to the canonical column', function (): void {
        $migration = require
            base_path('database/migrations/2026_08_31_165346_add_clusters_and_node_network_identity.php');

        $migration->down();

        $timestamp = now();
        DB::table('nodes')->insert([
            ...clusterLegacyNode('legacy-node', '192.0.2.10', $timestamp),
            'wireguard_address' => '10.44.0.10',
            'settings' => json_encode([
                'instance' => ['path' => '/srv/legacy-instances'],
                'worktree' => ['path' => '/srv/legacy-worktrees'],
            ], JSON_THROW_ON_ERROR),
        ]);

        $migration->up();

        $node = DB::table('nodes')->where('name', 'legacy-node')->first();

        expect(Schema::hasTable('clusters'))
            ->toBeTrue()
            ->and(Schema::hasColumn('nodes', 'cluster_id'))
            ->toBeTrue()
            ->and(Schema::hasColumn('nodes', 'wireguard_ip'))
            ->toBeTrue()
            ->and(Schema::hasColumn('nodes', 'wireguard_address'))
            ->toBeTrue()
            ->and(Schema::hasColumn('nodes', 'lan_ip'))
            ->toBeTrue()
            ->and($node->cluster_id)
            ->toBeNull()
            ->and($node->wireguard_ip)
            ->toBe('10.44.0.10')
            ->and($node->wireguard_address)
            ->toBe('10.44.0.10')
            ->and(json_decode($node->settings, true, flags: JSON_THROW_ON_ERROR))
            ->toBe([
                'instance' => ['path' => '/srv/legacy-instances'],
                'worktree' => ['path' => '/srv/legacy-worktrees'],
            ]);
    });

    it('enforces Cluster-scoped LAN and Router ownership constraints', function (): void {
        $firstCluster = DB::table('clusters')->insertGetId([
            'name' => 'first',
            'state' => 'inactive',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $secondCluster = DB::table('clusters')->insertGetId([
            'name' => 'second',
            'state' => 'inactive',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $firstNode = DB::table('nodes')->insertGetId([
            ...clusterLegacyNode('first-node', '192.0.2.11', now()),
            'cluster_id' => $firstCluster,
            'lan_ip' => '10.0.0.10',
        ]);
        $secondNode = DB::table('nodes')->insertGetId([
            ...clusterLegacyNode('second-node', '192.0.2.12', now()),
            'cluster_id' => $secondCluster,
            'lan_ip' => '10.0.0.10',
        ]);

        expect($firstNode)->toBeInt()->and($secondNode)->toBeInt();

        expect(fn () => DB::table('nodes')->insert([
            ...clusterLegacyNode('duplicate-lan', '192.0.2.13', now()),
            'cluster_id' => $firstCluster,
            'lan_ip' => '10.0.0.10',
        ]))
            ->toThrow(QueryException::class);

        DB::table('node_roles')->insert([
            'node_id' => $firstNode,
            'cluster_id' => $firstCluster,
            'role' => 'router',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(fn () => DB::table('node_roles')->insert([
            'node_id' => DB::table('nodes')->insertGetId([
                ...clusterLegacyNode('other-first-node', '192.0.2.14', now()),
                'cluster_id' => $firstCluster,
            ]),
            'cluster_id' => $firstCluster,
            'role' => 'router',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]))
            ->toThrow(QueryException::class);

        expect(fn () => DB::table('node_roles')->insert([
            'node_id' => $secondNode,
            'cluster_id' => $firstCluster,
            'role' => 'router',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]))
            ->toThrow(QueryException::class);
    });
});

/**
 * @return array<string, mixed>
 */
function clusterLegacyNode(string $name, string $host, mixed $timestamp): array
{
    return [
        'name' => $name,
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => $host,
        'user' => 'orbit',
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ];
}
