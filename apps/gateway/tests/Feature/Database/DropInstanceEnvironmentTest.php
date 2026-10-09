<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(fn () => restore_app_era_instance_leftovers_for_migration_test());
afterEach(fn () => drop_app_era_instance_leftovers_for_migration_test());

function drop_app_instance_environment_migration(): object
{
    return require base_path('database/migrations/2026_09_30_100000_drop_environment_from_app_instances.php');
}

it('derives Instance placement from Node roles and restores the column and constraints on rollback', function (): void {
    $migration = drop_app_instance_environment_migration();
    run_legacy_schema_migration($migration, 'down');

    try {
        $project = Project::query()->create([
            'name' => 'Placement',
            'slug' => 'placement',
            'repository_url' => 'https://example.test/placement.git',
            'default_branch' => 'main',
            'apps' => fixture_apps(null),
        ]);
        $node = Node::query()->create([
            'name' => 'placement-prod',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.91',
            'wireguard_ip' => '10.44.0.91',
        ]);
        $node->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
        $instance = Instance::query()->create([
            'project_id' => $project->id,
            'node_id' => $node->id,
            'name' => 'main',
            'checkout_path' => '/var/www/placement/releases/one',
            'production_user' => 'placement',
            'production_home' => '/var/www/placement',
            'branch' => 'main',
            'starting_commit' => str_repeat('a', 40),
            'status' => InstanceState::Active,
        ]);
        DB::table('instances')->where('id', $instance->id)->update(['environment' => 'production']);

        run_legacy_schema_migration($migration, 'up');

        expect(Schema::hasColumn('instances', 'environment'))
            ->toBeFalse()
            ->and($instance->fresh()->placedOnAppProd())
            ->toBeTrue()
            ->and($instance->fresh()->defaultAppEnv())
            ->toBe('production')
            ->and(DB::table('sqlite_master')
                ->where('type', 'trigger')
                ->whereIn('name', ['routes_contract_update', 'route_targets_contract_insert', 'production_route_target_instances_update', 'instance_removal_members_insert'])
                ->where('sql', 'like', '%instances.environment%')
                ->exists())
            ->toBeFalse();

        expect(fn () => Instance::query()->create([
            'project_id' => $project->id,
            'node_id' => $node->id,
            'name' => 'duplicate',
            'checkout_path' => '/var/www/placement/releases/two',
            'production_user' => 'placement',
            'production_home' => '/var/www/placement',
            'branch' => 'main',
            'starting_commit' => str_repeat('b', 40),
            'status' => InstanceState::Active,
        ]))->toThrow(QueryException::class);

        run_legacy_schema_migration($migration, 'down');

        expect(Schema::hasColumn('instances', 'environment'))
            ->toBeTrue()
            ->and(DB::table('instances')->where('id', $instance->id)->value('environment'))
            ->toBe('production');
    } finally {
        if (! Schema::hasColumn('instances', 'environment')) {
            run_legacy_schema_migration($migration, 'down');
        }
        run_legacy_schema_migration($migration, 'up');
    }
});

it('rolls back the restored column and trigger rewrites when down fails and can retry', function (): void {
    $migration = drop_app_instance_environment_migration();
    run_legacy_schema_migration($migration, 'down');

    try {
        $project = Project::query()->create([
            'name' => 'Atomic rollback',
            'slug' => 'atomic-rollback',
            'repository_url' => 'https://example.test/atomic-rollback.git',
            'apps' => fixture_apps(null),
        ]);
        $node = Node::query()->create([
            'name' => 'atomic-rollback-prod',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.95',
            'wireguard_ip' => '10.44.0.95',
        ]);
        $node->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
        $instance = Instance::query()->create([
            'project_id' => $project->id,
            'node_id' => $node->id,
            'name' => 'main',
            'checkout_path' => '/home/atomic-rollback/releases/one',
            'production_user' => 'atomic-rollback',
            'production_home' => '/home/atomic-rollback',
            'status' => InstanceState::Active,
        ]);
        DB::table('instances')->where('id', $instance->id)->update(['environment' => 'production']);
        run_legacy_schema_migration($migration, 'up');
        $triggersBefore = DB::table('sqlite_master')
            ->where('type', 'trigger')
            ->orderBy('name')
            ->pluck('sql', 'name')
            ->all();
        $inject = true;
        DB::listen(static function (QueryExecuted $query) use (&$inject): void {
            if ($inject && str_contains($query->sql, 'CREATE TRIGGER app_instances_production_placement_insert')) {
                $inject = false;
                throw new RuntimeException('Injected rollback trigger recreation failure.');
            }
        });

        expect(fn () => run_legacy_schema_migration($migration, 'down'))
            ->toThrow(RuntimeException::class, 'Injected rollback trigger recreation failure.');

        expect(Schema::hasColumn('instances', 'environment'))
            ->toBeFalse()
            ->and(DB::table('sqlite_master')
                ->where('type', 'trigger')
                ->orderBy('name')
                ->pluck('sql', 'name')
                ->all())
            ->toBe($triggersBefore)
            ->and(fn () => Instance::query()->create([
                'project_id' => $project->id,
                'node_id' => $node->id,
                'name' => 'duplicate-after-failed-rollback',
                'checkout_path' => '/home/atomic-rollback/releases/two',
                'production_user' => 'atomic-rollback',
                'production_home' => '/home/atomic-rollback',
                'branch' => 'main',
                'starting_commit' => str_repeat('c', 40),
                'status' => InstanceState::Active,
            ]))
            ->toThrow(QueryException::class);

        run_legacy_schema_migration($migration, 'down');

        expect(Schema::hasColumn('instances', 'environment'))
            ->toBeTrue()
            ->and(DB::table('instances')->where('id', $instance->id)->value('environment'))
            ->toBe('production')
            ->and(DB::table('sqlite_master')
                ->where('type', 'trigger')
                ->where('name', 'instances_production_placement_insert')
                ->where('sql', 'like', '%NEW.environment = \'production\'%')
                ->exists())
            ->toBeTrue();
    } finally {
        if (Schema::hasColumn('instances', 'environment')) {
            run_legacy_schema_migration($migration, 'up');
        }
    }
});

it('rolls back trigger rewrites when an injected migration failure interrupts trigger recreation', function (): void {
    $migration = drop_app_instance_environment_migration();
    run_legacy_schema_migration($migration, 'down');

    try {
        $project = Project::query()->create([
            'name' => 'Atomic placement',
            'slug' => 'atomic-placement',
            'repository_url' => 'https://example.test/atomic-placement.git',
            'apps' => fixture_apps(null),
        ]);
        $node = Node::query()->create([
            'name' => 'atomic-placement-prod',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.93',
            'wireguard_ip' => '10.44.0.93',
        ]);
        $node->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
        $instance = Instance::query()->create([
            'project_id' => $project->id,
            'node_id' => $node->id,
            'name' => 'main',
            'checkout_path' => '/home/atomic-placement/releases/one',
            'production_user' => 'atomic-placement',
            'production_home' => '/home/atomic-placement',
            'status' => InstanceState::Active,
        ]);
        DB::table('instances')->where('id', $instance->id)->update(['environment' => 'production']);
        $triggersBefore = DB::table('sqlite_master')
            ->where('type', 'trigger')
            ->orderBy('name')
            ->pluck('sql', 'name')
            ->all();
        $inject = true;
        DB::listen(static function (QueryExecuted $query) use (&$inject): void {
            if ($inject && str_contains($query->sql, 'CREATE TRIGGER app_instances_production_placement_insert')) {
                $inject = false;
                throw new RuntimeException('Injected trigger recreation failure.');
            }
        });

        expect(fn () => run_legacy_schema_migration($migration, 'up'))
            ->toThrow(RuntimeException::class, 'Injected trigger recreation failure.');

        expect(Schema::hasColumn('instances', 'environment'))
            ->toBeTrue()
            ->and(DB::table('sqlite_master')
                ->where('type', 'trigger')
                ->orderBy('name')
                ->pluck('sql', 'name')
                ->all())
            ->toBe($triggersBefore)
            ->and(fn () => DB::table('instances')->insert([
                'project_id' => $project->id,
                'node_id' => $node->id,
                'name' => 'duplicate-before-retry',
                'environment' => 'production',
                'source_layout' => 'checkout',
                'checkout_path' => '/home/atomic-placement/releases/two',
                'migration_required' => false,
                'status' => 'reserved',
                'created_at' => now(),
                'updated_at' => now(),
            ]))
            ->toThrow(QueryException::class);

        run_legacy_schema_migration($migration, 'up');

        expect(Schema::hasColumn('instances', 'environment'))
            ->toBeFalse()
            ->and(DB::table('sqlite_master')
                ->where('type', 'trigger')
                ->where('name', 'instances_production_placement_insert')
                ->exists())
            ->toBeTrue();
    } finally {
        if (! Schema::hasColumn('instances', 'environment')) {
            run_legacy_schema_migration($migration, 'down');
        }
        run_legacy_schema_migration($migration, 'up');
    }
});

it('refuses to drop the column when an Instance has no active or removing matching Node role', function (): void {
    $migration = drop_app_instance_environment_migration();
    run_legacy_schema_migration($migration, 'down');

    try {
        $project = Project::query()->create([
            'name' => 'Unplaced',
            'slug' => 'unplaced',
            'repository_url' => 'https://example.test/unplaced.git',
            'default_branch' => 'main',
            'apps' => fixture_apps(null),
        ]);
        $node = Node::query()->create([
            'name' => 'unplaced-node',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.92',
            'wireguard_ip' => '10.44.0.92',
        ]);
        $instance = Instance::query()->create([
            'project_id' => $project->id,
            'node_id' => $node->id,
            'name' => 'main',
            'environment' => 'production',
            'checkout_path' => '/var/www/unplaced/releases/one',
            'branch' => 'main',
            'starting_commit' => str_repeat('a', 40),
            'status' => InstanceState::Active,
        ]);

        expect(fn () => run_legacy_schema_migration($migration, 'up'))
            ->toThrow(RuntimeException::class, "Cannot remove AppInstance environment without a usable matching Node role for Instances: {$instance->id}.")
            ->and(Schema::hasColumn('instances', 'environment'))
            ->toBeTrue();

        $instance->delete();
        run_legacy_schema_migration($migration, 'up');
    } finally {
        if (! Schema::hasColumn('instances', 'environment')) {
            run_legacy_schema_migration($migration, 'down');
        }
        run_legacy_schema_migration($migration, 'up');
    }
});
