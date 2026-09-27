<?php

declare(strict_types=1);

use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\Process;
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
    $migration->up();

    expect(DB::table('settings')->where('key', 'extension.tasks.enabled')->value('value'))->toBe('1')
        ->and(DB::table('settings')->where('key', 'extension.proxycli.enabled')->value('value'))->toBe('1')
        ->and(DB::table('settings')->where('key', 'tasks.enabled')->exists())->toBeFalse()
        ->and(DB::table('settings')->where('key', 'proxycli.enabled')->value('value'))->toBe('1');
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
    $migration->up();

    expect(DB::table('settings')->where('key', 'extension.proxycli.enabled')->exists())->toBeFalse()
        ->and(DB::table('settings')->where('key', 'proxycli.enabled')->value('value'))->toBe('1');
});
