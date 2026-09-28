<?php

declare(strict_types=1);

use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('preserves the live extension state while moving legacy task switches', function (): void {
    $now = now();
    $gateway = Node::query()->create([
        'name' => 'migration-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.99',
        'wireguard_ip' => '10.44.0.99',
    ]);
    foreach ([['tasks.enabled', '1'], ['proxycli.enabled', '1'], ['proxycli.node_id', (string) $gateway->id]] as [$key, $value]) {
        DB::table('settings')->insert([
            'scope_type' => 'gateway', 'scope_id' => 0, 'key' => $key, 'value' => $value,
            'is_secret' => false, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
    Process::query()->create([
        'owner_type' => Node::class,
        'owner_id' => $gateway->id,
        'name' => 'cli-proxy-api-collector',
        'runtime' => ProcessRuntime::Systemd,
        'working_directory' => '/var/lib/orbit/proxycli',
        'runtime_config' => ['command' => []],
        'restart_policy' => 'unless-stopped',
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Active,
    ]);

    $migration = require database_path('migrations/2026_10_01_000001_move_extension_switches_to_gateway_extension_keys.php');
    run_legacy_schema_migration($migration, 'up');

    expect(DB::table('settings')->where('key', 'extension.tasks.enabled')->value('value'))->toBe('1')
        ->and(DB::table('settings')->where('key', 'extension.proxycli.enabled')->value('value'))->toBe('1')
        ->and(DB::table('settings')->where('key', 'tasks.enabled')->exists())->toBeFalse()
        ->and(DB::table('settings')->where('key', 'proxycli.enabled')->value('value'))->toBe('1');
});

it('restores the legacy task switch when rolling back an enabled extension', function (): void {
    $now = now();
    DB::table('settings')->insert([
        'scope_type' => 'gateway', 'scope_id' => 0, 'key' => 'extension.tasks.enabled', 'value' => '1',
        'is_secret' => false, 'created_at' => $now, 'updated_at' => $now,
    ]);

    $migration = require database_path('migrations/2026_10_01_000001_move_extension_switches_to_gateway_extension_keys.php');
    run_legacy_schema_migration($migration, 'down');

    expect(DB::table('settings')->where('key', 'tasks.enabled')->value('value'))->toBe('1')
        ->and(DB::table('settings')->where('key', 'extension.tasks.enabled')->exists())->toBeFalse();
});

it('restores the task switch before a failed deletion and can retry the rollback', function (): void {
    $now = now();
    DB::table('settings')->insert([
        'scope_type' => 'gateway', 'scope_id' => 0, 'key' => 'extension.tasks.enabled', 'value' => '1',
        'is_secret' => false, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::unprepared(<<<'SQL'
        CREATE TEMP TRIGGER fail_extension_switch_delete
        BEFORE DELETE ON settings
        WHEN OLD.scope_type = 'gateway' AND OLD.scope_id = 0 AND OLD.key = 'extension.tasks.enabled'
        BEGIN SELECT RAISE(ABORT, 'injected extension rollback failure'); END
        SQL);

    $migration = require database_path('migrations/2026_10_01_000001_move_extension_switches_to_gateway_extension_keys.php');

    expect(fn () => run_legacy_schema_migration($migration, 'down'))
        ->toThrow(QueryException::class, 'injected extension rollback failure');

    expect(DB::table('settings')->where('key', 'tasks.enabled')->value('value'))->toBe('1')
        ->and(DB::table('settings')->where('key', 'extension.tasks.enabled')->exists())->toBeTrue();

    DB::unprepared('DROP TRIGGER fail_extension_switch_delete');
    run_legacy_schema_migration($migration, 'down');

    expect(DB::table('settings')->where('key', 'tasks.enabled')->value('value'))->toBe('1')
        ->and(DB::table('settings')->where('key', 'extension.tasks.enabled')->exists())->toBeFalse();
});

it('keeps a configured but stopped proxycli collector disabled', function (): void {
    $now = now();
    foreach ([['proxycli.enabled', '1'], ['proxycli.node_id', '1']] as [$key, $value]) {
        DB::table('settings')->insert([
            'scope_type' => 'gateway', 'scope_id' => 0, 'key' => $key, 'value' => $value,
            'is_secret' => false, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    $migration = require database_path('migrations/2026_10_01_000001_move_extension_switches_to_gateway_extension_keys.php');
    run_legacy_schema_migration($migration, 'up');

    expect(DB::table('settings')->where('key', 'extension.proxycli.enabled')->exists())->toBeFalse()
        ->and(DB::table('settings')->where('key', 'proxycli.enabled')->value('value'))->toBe('1');
});
