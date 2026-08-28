<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

describe('managed node user migrations', function (): void {
    it('expands, contracts, and rolls back with legacy write compatibility', function (): void {
        $contract = require base_path('database/migrations/2026_08_28_000001_drop_node_ssh_user.php');
        $expand = require base_path('database/migrations/2026_08_28_000000_add_node_user.php');

        $contract->down();
        $expand->down();

        expect(Schema::hasColumn('nodes', 'ssh_user'))
            ->toBeTrue()
            ->and(Schema::hasColumn('nodes', 'user'))
            ->toBeFalse();

        $timestamp = now();

        DB::table('nodes')->insert([
            legacyNode('legacy-orbit', '192.0.2.1', 'orbit', $timestamp),
            legacyNode('legacy-nckrtl', '192.0.2.2', 'nckrtl', $timestamp),
            legacyNode('legacy-empty', '192.0.2.3', '', $timestamp),
            legacyNode('legacy-whitespace', '192.0.2.4', ' nckrtl ', $timestamp),
            legacyNode('legacy-nul', '192.0.2.7', "nck\0rtl", $timestamp),
        ]);

        expect(fn () => $expand->up())
            ->toThrow(RuntimeException::class, 'Invalid legacy node user.');

        DB::table('nodes')->where('name', 'legacy-whitespace')->delete();
        DB::table('nodes')->where('name', 'legacy-nul')->delete();
        $expand->up();

        expect(Schema::hasColumn('nodes', 'ssh_user'))
            ->toBeTrue()
            ->and(Schema::hasColumn('nodes', 'user'))
            ->toBeTrue()
            ->and(DB::table('nodes')->where('name', 'legacy-orbit')->value('user'))
            ->toBe('orbit')
            ->and(DB::table('nodes')->where('name', 'legacy-nckrtl')->value('user'))
            ->toBe('nckrtl')
            ->and(DB::table('nodes')->where('name', 'legacy-empty')->value('user'))
            ->toBe('orbit')
            ->and(DB::table('nodes')->where('name', 'legacy-whitespace')->exists())
            ->toBeFalse();

        DB::table('nodes')->insert(legacyNode('legacy-write', '192.0.2.5', 'nckrtl', $timestamp));

        expect(DB::table('nodes')->where('name', 'legacy-write')->value('user'))
            ->toBe('nckrtl');

        DB::table('nodes')->where('name', 'legacy-write')->update(['ssh_user' => '']);

        expect(DB::table('nodes')->where('name', 'legacy-write')->value('user'))
            ->toBe('orbit');

        expect(fn () => DB::table('nodes')->insert(legacyNode('invalid-insert', '192.0.2.6', 'bad/name', $timestamp)))
            ->toThrow(RuntimeException::class, 'Invalid legacy node user.');

        expect(fn () => DB::table('nodes')->insert(legacyNode(
            'invalid-nul-insert',
            '192.0.2.8',
            "nck\0rtl",
            $timestamp,
        )))
            ->toThrow(RuntimeException::class, 'Invalid legacy node user.');

        expect(fn () => DB::table('nodes')->where('name', 'legacy-write')->update(['ssh_user' => '9bad']))
            ->toThrow(RuntimeException::class, 'Invalid legacy node user.');

        $contract->up();

        $columns = collect(Schema::getColumns('nodes'))->keyBy('name');

        expect(Schema::hasColumn('nodes', 'ssh_user'))
            ->toBeFalse()
            ->and(Schema::hasColumn('nodes', 'user'))
            ->toBeTrue()
            ->and($columns['user']['nullable'])
            ->toBeFalse()
            ->and(trim((string) $columns['user']['default'], "'\""))
            ->toBe('orbit')
            ->and(DB::table('nodes')->whereNull('user')->orWhere('user', '')->exists())
            ->toBeFalse();

        $contract->down();

        expect(Schema::hasColumn('nodes', 'ssh_user'))
            ->toBeTrue()
            ->and(Schema::hasColumn('nodes', 'user'))
            ->toBeTrue()
            ->and(DB::table('nodes')->where('name', 'legacy-nckrtl')->value('ssh_user'))
            ->toBe('nckrtl')
            ->and(DB::table('nodes')->where('name', 'legacy-whitespace')->exists())
            ->toBeFalse();

        DB::table('nodes')->where('name', 'legacy-write')->update(['ssh_user' => 'nckrtl']);

        expect(DB::table('nodes')->where('name', 'legacy-write')->value('user'))
            ->toBe('nckrtl');

        $expand->down();

        expect(Schema::hasColumn('nodes', 'ssh_user'))
            ->toBeTrue()
            ->and(Schema::hasColumn('nodes', 'user'))
            ->toBeFalse()
            ->and(DB::table('nodes')->where('name', 'legacy-nckrtl')->value('ssh_user'))
            ->toBe('nckrtl')
            ->and(DB::table('nodes')->where('name', 'legacy-empty')->value('ssh_user'))
            ->toBe('orbit');
    });
});

/**
 * @return array<string, mixed>
 */
function legacyNode(string $name, string $host, string $user, mixed $timestamp): array
{
    return [
        'name' => $name,
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => $host,
        'ssh_user' => $user,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ];
}
