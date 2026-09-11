<?php

declare(strict_types=1);

use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('backfills prior production branches while preserving source evidence and null compatibility', function (): void {
    $migration = app_instance_deployment_config_migration();
    DB::table('app_instances')->update(['deployment_branch' => null, 'deployment_steps' => null]);
    $migration->down();
    [$app, $node] = deployment_migration_parents();
    $configured = DB::table('app_instances')->insertGetId([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'configured',
        'environment' => 'production',
        'source_layout' => 'release',
        'checkout_path' => '/home/configured/releases/initial',
        'branch' => 'release/one',
        'branch_override' => 'release/one',
        'migration_required' => false,
        'status' => 'source_resolved',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $otherNode = Node::query()->create([
        'name' => 'deployment-migration-incomplete',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.199',
    ]);
    $incomplete = DB::table('app_instances')->insertGetId([
        'app_id' => $app->id,
        'node_id' => $otherNode->id,
        'name' => 'incomplete',
        'environment' => 'development',
        'source_layout' => 'checkout',
        'checkout_path' => '/srv/incomplete',
        'branch' => null,
        'branch_override' => null,
        'migration_required' => false,
        'status' => 'reserved',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    try {
        DB::table('app_instances')->where('id', $incomplete)->update(['environment' => 'production']);
        $migration->up();

        $configuredRow = DB::table('app_instances')->find($configured);
        $incompleteRow = DB::table('app_instances')->find($incomplete);

        expect(Schema::hasColumns('app_instances', ['deployment_branch', 'deployment_steps']))->toBeTrue()
            ->and($configuredRow->deployment_branch)->toBe('release/one')
            ->and($configuredRow->branch)->toBe('release/one')
            ->and($configuredRow->branch_override)->toBe('release/one')
            ->and($incompleteRow->deployment_branch)->toBeNull()
            ->and(AppInstance::query()->findOrFail($configured)->deployment_steps)->toBe([])
            ->and(AppInstance::query()->findOrFail($incomplete)->deployment_steps)->toBe([]);
    } finally {
        if (! Schema::hasColumn('app_instances', 'deployment_branch')) {
            $migration->up();
        }
    }
});

it('refuses rollback before discarding configured deployment state', function (): void {
    $migration = app_instance_deployment_config_migration();
    [$app, $node] = deployment_migration_parents();
    AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'rollback',
        'environment' => 'production',
        'checkout_path' => '/home/rollback/releases/initial',
        'branch' => 'main',
        'deployment_branch' => 'main',
        'status' => 'source_resolved',
    ]);

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Cannot discard configured AppInstance deployment state.')
        ->and(Schema::hasColumns('app_instances', ['deployment_branch', 'deployment_steps']))->toBeTrue();
});

function app_instance_deployment_config_migration(): object
{
    return require base_path(
        'database/migrations/2026_09_11_000000_add_deployment_config_to_app_instances.php',
    );
}

/** @return array{OrbitApp, Node} */
function deployment_migration_parents(): array
{
    $count = Node::query()->count();
    $node = Node::query()->create([
        'name' => "deployment-migration-{$count}",
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.'.(130 + $count),
    ]);
    $app = OrbitApp::query()->create([
        'name' => "Deployment migration {$count}",
        'slug' => "deployment-migration-{$count}",
        'repository_url' => "https://example.test/deployment-migration-{$count}.git",
        'default_branch' => 'main',
        'root' => 'public',
    ]);

    return [$app, $node];
}
