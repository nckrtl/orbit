<?php

declare(strict_types=1);

use App\Models\AppInstance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('copies JSON deploy steps into named records and drops the JSON column', function (): void {
    $legacy = app_instance_deployment_config_migration();
    $records = app_instance_deploy_step_records_migration();
    $records->down();
    DB::table('app_instances')->update(['deployment_branch' => null, 'deployment_steps' => null]);
    $legacy->down();
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

    try {
        $legacy->up();
        DB::table('app_instances')->where('id', $configured)->update([
            'deployment_steps' => json_encode([
                [
                    'name' => 'migrate',
                    'phase' => 'before_activation',
                    'command' => 'php artisan migrate --force',
                    'timeout_seconds' => 300,
                ],
            ], JSON_THROW_ON_ERROR),
        ]);
        $records->up();

        expect(Schema::hasColumn('app_instances', 'deployment_branch'))->toBeTrue()
            ->and(Schema::hasColumn('app_instances', 'deployment_steps'))->toBeFalse()
            ->and(Schema::hasTable('app_instance_deploy_steps'))->toBeTrue()
            ->and(DB::table('app_instances')->find($configured)->deployment_branch)->toBe('release/one')
            ->and(normalized_deploy_steps(AppInstance::query()->findOrFail($configured)))->toBe([
                [
                    'name' => 'migrate',
                    'phase' => 'before_activation',
                    'command' => 'php artisan migrate --force',
                    'timeout_seconds' => 300,
                ],
            ]);
    } finally {
        if (! Schema::hasTable('app_instance_deploy_steps')) {
            $records->up();
        }
    }
});
