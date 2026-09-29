<?php

declare(strict_types=1);

use App\Models\Instance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(fn () => roll_back_app_instance_environment_for_migration_test());
afterEach(fn () => restore_app_instance_environment_schema_for_migration_test());

it('copies JSON deploy steps into named records and drops the JSON column', function (): void {
    $legacy = app_instance_deployment_config_migration();
    $records = app_instance_deploy_step_records_migration();
    run_legacy_schema_migration($records, 'down');
    DB::table('instances')->update(['deployment_branch' => null, 'deployment_steps' => null]);
    run_legacy_schema_migration($legacy, 'down');
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

    try {
        run_legacy_schema_migration($legacy, 'up');
        DB::table('instances')->where('id', $configured)->update([
            'deployment_steps' => json_encode([
                [
                    'name' => 'migrate',
                    'phase' => 'before_activation',
                    'command' => 'php artisan migrate --force',
                    'timeout_seconds' => 300,
                ],
            ], JSON_THROW_ON_ERROR),
        ]);
        run_legacy_schema_migration($records, 'up');

        expect(Schema::hasColumn('instances', 'deployment_branch'))->toBeTrue()
            ->and(Schema::hasColumn('instances', 'deployment_steps'))->toBeFalse()
            ->and(Schema::hasTable('instance_deploy_steps'))->toBeTrue()
            ->and(DB::table('instances')->find($configured)->deployment_branch)->toBe('release/one')
            ->and(normalized_deploy_steps(Instance::query()->findOrFail($configured)))->toBe([
                [
                    'name' => 'migrate',
                    'phase' => 'before_activation',
                    'command' => 'php artisan migrate --force',
                    'timeout_seconds' => 300,
                ],
            ]);
    } finally {
        if (! Schema::hasTable('instance_deploy_steps')) {
            run_legacy_schema_migration($records, 'up');
        }
    }
});
