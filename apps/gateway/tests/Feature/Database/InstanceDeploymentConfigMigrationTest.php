<?php

declare(strict_types=1);

use App\Models\Instance;
use App\Models\Node;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(fn () => roll_back_app_instance_environment_for_migration_test());
afterEach(fn () => restore_app_instance_environment_schema_for_migration_test());

it('backfills prior production branches while preserving source evidence and null compatibility', function (): void {
    $records = app_instance_deploy_step_records_migration();
    run_legacy_schema_migration($records, 'down');
    $migration = app_instance_deployment_config_migration();
    DB::table('instances')->update(['deployment_branch' => null, 'deployment_steps' => null]);
    run_legacy_schema_migration($migration, 'down');
    [$project, $node] = deployment_migration_parents();
    $configured = DB::table('instances')->insertGetId([
        'project_id' => $project->id,
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
    $incomplete = DB::table('instances')->insertGetId([
        'project_id' => $project->id,
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
        DB::table('instances')->where('id', $incomplete)->update(['environment' => 'production']);
        run_legacy_schema_migration($migration, 'up');
        run_legacy_schema_migration($records, 'up');

        $configuredRow = DB::table('instances')->find($configured);
        $incompleteRow = DB::table('instances')->find($incomplete);

        expect(Schema::hasColumn('instances', 'deployment_branch'))->toBeTrue()
            ->and(Schema::hasColumn('instances', 'deployment_steps'))->toBeFalse()
            ->and($configuredRow->deployment_branch)->toBe('release/one')
            ->and($configuredRow->branch)->toBe('release/one')
            ->and($configuredRow->branch_override)->toBe('release/one')
            ->and($incompleteRow->deployment_branch)->toBeNull()
            ->and(normalized_deploy_steps(Instance::query()->findOrFail($configured)))->toBe([])
            ->and(normalized_deploy_steps(Instance::query()->findOrFail($incomplete)))->toBe([]);
    } finally {
        if (! Schema::hasTable('instance_deploy_steps')) {
            run_legacy_schema_migration($records, 'up');
        }
    }
});

it('refuses rollback before discarding configured deployment state', function (): void {
    $records = app_instance_deploy_step_records_migration();
    run_legacy_schema_migration($records, 'down');
    $migration = app_instance_deployment_config_migration();
    [$project, $node] = deployment_migration_parents();
    Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'rollback',
        'environment' => 'production',
        'checkout_path' => '/home/rollback/releases/initial',
        'branch' => 'main',
        'deployment_branch' => 'main',
        'status' => 'source_resolved',
    ]);

    expect(fn () => run_legacy_schema_migration($migration, 'down'))
        ->toThrow(RuntimeException::class, 'Cannot discard configured AppInstance deployment state.')
        ->and(Schema::hasColumns('instances', ['deployment_branch', 'deployment_steps']))->toBeTrue();

    run_legacy_schema_migration($records, 'up');
});
