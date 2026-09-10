<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

describe('obsolete WireGuard address migration', function (): void {
    it('removes the synchronized alias and preserves canonical identities', function (): void {
        $migration = obsoleteWireguardAddressMigration();
        $migration->down();

        DB::table('nodes')->insert([
            obsoleteWireguardAddressNode('assigned', '192.0.2.10', '10.44.0.10'),
            obsoleteWireguardAddressNode('unassigned-first', '192.0.2.11', null),
            obsoleteWireguardAddressNode('unassigned-second', '192.0.2.12', null),
        ]);

        $migration->up();

        expect(Schema::hasColumn('nodes', 'wireguard_address'))
            ->toBeFalse()
            ->and(DB::table('nodes')->where('name', 'assigned')->sole()->wireguard_ip)
            ->toBe('10.44.0.10')
            ->and(DB::table('nodes')->whereNull('wireguard_ip')->count())
            ->toBe(2)
            ->and(obsoleteWireguardAddressSchemaObjectCount([
                'nodes_wireguard_address_unique',
                'nodes_wireguard_identity_insert',
                'nodes_wireguard_ip_update',
                'nodes_wireguard_address_update',
            ]))
            ->toBe(0)
            ->and(obsoleteWireguardAddressSchemaObjectCount(['nodes_wireguard_ip_unique']))
            ->toBe(1);

        expect(fn () => DB::table('nodes')->insert(
            obsoleteWireguardAddressNode('duplicate', '192.0.2.13', '10.44.0.10'),
        ))
            ->toThrow(QueryException::class);
    });

    it('restores synchronized aliases when rolled back', function (): void {
        DB::table('nodes')->insert([
            obsoleteWireguardAddressNode('assigned', '192.0.2.20', '10.44.0.20'),
            obsoleteWireguardAddressNode('unassigned', '192.0.2.21', null),
        ]);

        obsoleteWireguardAddressMigration()->down();

        expect(Schema::hasColumn('nodes', 'wireguard_address'))
            ->toBeTrue()
            ->and(DB::table('nodes')->where('name', 'assigned')->sole()->wireguard_address)
            ->toBe('10.44.0.20')
            ->and(DB::table('nodes')->where('name', 'unassigned')->sole()->wireguard_address)
            ->toBeNull()
            ->and(obsoleteWireguardAddressSchemaObjectCount([
                'nodes_wireguard_address_unique',
                'nodes_wireguard_identity_insert',
                'nodes_wireguard_ip_update',
                'nodes_wireguard_address_update',
            ]))
            ->toBe(4);

        DB::table('nodes')->where('name', 'assigned')->update(['wireguard_ip' => '10.44.0.22']);

        expect(DB::table('nodes')->where('name', 'assigned')->sole()->wireguard_address)
            ->toBe('10.44.0.22');
    });

    it('refuses to remove an alias that differs from the canonical identity', function (): void {
        $migration = obsoleteWireguardAddressMigration();
        $migration->down();
        DB::table('nodes')->insert(
            obsoleteWireguardAddressNode('mismatched', '192.0.2.30', '10.44.0.30'),
        );
        DB::statement('DROP TRIGGER nodes_wireguard_address_update');
        DB::statement('DROP TRIGGER nodes_wireguard_ip_update');
        DB::statement('DROP TRIGGER nodes_wireguard_identity_insert');
        DB::table('nodes')
            ->where('name', 'mismatched')
            ->update([
                'wireguard_address' => '10.44.0.31',
            ]);

        expect(fn () => $migration->up())
            ->toThrow(
                RuntimeException::class,
                'Cannot remove wireguard_address while Node WireGuard identities differ.',
            )
            ->and(Schema::hasColumn('nodes', 'wireguard_address'))
            ->toBeTrue()
            ->and(DB::table('nodes')->where('name', 'mismatched')->sole()->wireguard_ip)
            ->toBe('10.44.0.30')
            ->and(DB::table('nodes')->where('name', 'mismatched')->sole()->wireguard_address)
            ->toBe('10.44.0.31')
            ->and(obsoleteWireguardAddressSchemaObjectCount(['nodes_wireguard_address_unique']))
            ->toBe(1);
    });
});

function obsoleteWireguardAddressMigration(): object
{
    return require base_path(
        'database/migrations/2026_09_07_091259_remove_obsolete_wireguard_address_from_nodes_table.php',
    );
}

/** @return array<string, mixed> */
function obsoleteWireguardAddressNode(string $name, string $host, ?string $wireguardIp): array
{
    return [
        'name' => $name,
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => $host,
        'user' => 'orbit',
        'wireguard_ip' => $wireguardIp,
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

/** @param list<string> $names */
function obsoleteWireguardAddressSchemaObjectCount(array $names): int
{
    return DB::table('sqlite_master')->whereIn('name', $names)->count();
}
