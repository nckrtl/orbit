<?php

declare(strict_types=1);

use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('preserves existing Herdr sessions as managed through a reversible migration', function (): void {
    $node = Node::query()->create([
        'name' => 'herdr-management-migration',
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
    $migration = herdr_session_management_migration();
    $migration->down();
    $sessionId = DB::table('herdr_sessions')->insertGetId([
        'node_id' => $node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'process_id' => $process->id,
        'observer_port' => 23301,
        'observer_hostname' => 'commander-tasks.herdr.beast.orbit',
        'observer_url' => 'wss://commander-tasks.herdr.beast.orbit',
        'observer_status' => 'published',
        'observer_error' => null,
        'status' => 'active',
        'herdr_version' => '0.9.0',
        'protocol' => 22,
        'handoff_supported' => true,
        'publish_observer' => true,
        'failed_step' => null,
        'error_code' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $before = (array) DB::table('herdr_sessions')->find($sessionId);

    try {
        $migration->up();

        $managed = (array) DB::table('herdr_sessions')->find($sessionId);
        $management = $managed['management'];
        unset($managed['management']);

        expect(Schema::hasColumn('herdr_sessions', 'management'))
            ->toBeTrue()
            ->and($management)
            ->toBe('managed')
            ->and($managed)
            ->toBe($before)
            ->and($managed['process_id'])
            ->toBe($process->id);

        $migration->down();

        expect(Schema::hasColumn('herdr_sessions', 'management'))
            ->toBeFalse()
            ->and((array) DB::table('herdr_sessions')->find($sessionId))
            ->toBe($before);
    } finally {
        if (! Schema::hasColumn('herdr_sessions', 'management')) {
            $migration->up();
        }
    }
});

function herdr_session_management_migration(): object
{
    return require base_path(
        'database/migrations/2026_09_14_145247_add_management_to_herdr_sessions_table.php',
    );
}
