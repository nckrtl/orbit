<?php

declare(strict_types=1);

use App\Models\AppInstance;
use App\Models\Node;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('backfills prior production branches while preserving source evidence and null compatibility', function (): void {
    $records = app_instance_deploy_step_records_migration();
    $records->down();
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
        $records->up();

        $configuredRow = DB::table('app_instances')->find($configured);
        $incompleteRow = DB::table('app_instances')->find($incomplete);

        expect(Schema::hasColumn('app_instances', 'deployment_branch'))->toBeTrue()
            ->and(Schema::hasColumn('app_instances', 'deployment_steps'))->toBeFalse()
            ->and($configuredRow->deployment_branch)->toBe('release/one')
            ->and($configuredRow->branch)->toBe('release/one')
            ->and($configuredRow->branch_override)->toBe('release/one')
            ->and($incompleteRow->deployment_branch)->toBeNull()
            ->and(normalized_deploy_steps(AppInstance::query()->findOrFail($configured)))->toBe([])
            ->and(normalized_deploy_steps(AppInstance::query()->findOrFail($incomplete)))->toBe([]);
    } finally {
        if (! Schema::hasTable('app_instance_deploy_steps')) {
            $records->up();
        }
    }
});

it('refuses rollback before discarding configured deployment state', function (): void {
    $records = app_instance_deploy_step_records_migration();
    $records->down();
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

    $records->up();
});
