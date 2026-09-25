<?php

declare(strict_types=1);

use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('leaves no Herdr tables after migration', function (): void {
    expect(Schema::hasTable('herdr_sessions'))->toBeFalse()
        ->and(Schema::hasTable('herdr_observation_nonces'))->toBeFalse()
        ->and(Schema::hasTable('jwks_keys'))->toBeFalse();
});

it('drops Herdr tables that still hold rows and keeps Nodes and Processes', function (): void {
    drop_herdr_tables_migration('2026_09_13_210000_create_herdr_sessions.php')->up();
    drop_herdr_tables_migration('2026_09_14_145247_add_management_to_herdr_sessions_table.php')->up();
    $node = Node::query()->create([
        'name' => 'beast',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.45',
        'public_ssh_port' => 22,
        'user' => 'nckrtl',
        'wireguard_ip' => '10.44.0.45',
    ]);
    $process = Process::query()->create([
        'owner_type' => Node::class,
        'owner_id' => $node->id,
        'name' => 'herdr-commander-tasks',
        'runtime' => ProcessRuntime::Systemd,
        'working_directory' => '/home/nckrtl',
        'runtime_config' => ['command' => ['herdr', 'server']],
        'restart_policy' => 'unless-stopped',
        'desired_state' => 'running',
        'status' => LifecycleStatus::Active,
    ]);
    DB::table('herdr_sessions')->insert([
        'node_id' => $node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'process_id' => $process->id,
        'observer_port' => 23301,
        'observer_hostname' => 'commander-tasks.herdr.beast.orbit',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('jwks_keys')->insert([
        'kid' => 'retired-key',
        'algorithm' => 'RS256',
        'private_pem' => 'private',
        'public_pem' => 'public',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('herdr_observation_nonces')->insert(['jti' => 'retired-grant', 'expires_at' => now()]);

    $migration = drop_herdr_tables_migration('2026_09_25_120000_drop_herdr_tables.php');
    $migration->up();
    $migration->up();

    expect(Schema::hasTable('herdr_sessions'))->toBeFalse()
        ->and(Schema::hasTable('herdr_observation_nonces'))->toBeFalse()
        ->and(Schema::hasTable('jwks_keys'))->toBeFalse()
        ->and($node->fresh())->not->toBeNull()
        ->and($process->fresh())->not->toBeNull();
});

function drop_herdr_tables_migration(string $file): Migration
{
    return require base_path("database/migrations/{$file}");
}
