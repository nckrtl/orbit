<?php

declare(strict_types=1);

use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(fn () => roll_back_app_instance_environment_for_migration_test());
afterEach(fn () => restore_app_instance_environment_schema_for_migration_test());

it('adds nullable production identity and enforces one production placement per App and Node', function (): void {
    $migration = app_instance_production_identity_migration();
    run_legacy_schema_migration($migration, 'down');
    [$project, $node] = production_identity_migration_parents();
    $firstId = DB::table('instances')->insertGetId([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'first',
        'environment' => 'production',
        'source_layout' => 'checkout',
        'checkout_path' => '/home/orbit-app-1',
        'migration_required' => false,
        'status' => 'reserved',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    try {
        run_legacy_schema_migration($migration, 'up');

        expect(Schema::hasColumns('instances', ['production_user', 'production_home']))
            ->toBeTrue()
            ->and(DB::table('instances')->find($firstId)->production_user)
            ->toBeNull();

        $development = Instance::query()->create([
            'project_id' => $project->id,
            'node_id' => $node->id,
            'name' => 'development',
            'environment' => 'development',
            'checkout_path' => '/srv/development',
        ]);

        expect($development->exists)
            ->toBeTrue()
            ->and(fn () => DB::table('instances')->insert([
                'project_id' => $project->id,
                'node_id' => $node->id,
                'name' => 'second',
                'environment' => 'production',
                'source_layout' => 'checkout',
                'checkout_path' => '/home/orbit-app-1-second',
                'migration_required' => false,
                'status' => 'reserved',
                'created_at' => now(),
                'updated_at' => now(),
            ]))
            ->toThrow(QueryException::class);
    } finally {
        if (! Schema::hasColumn('instances', 'production_user')) {
            run_legacy_schema_migration($migration, 'up');
        }
    }
});

it('refuses rollback before discarding recorded production identity', function (): void {
    $migration = app_instance_production_identity_migration();
    [$project, $node] = production_identity_migration_parents();
    Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'production',
        'environment' => 'production',
        'checkout_path' => "/home/orbit-app-{$project->id}",
        'production_user' => "orbit-app-{$project->id}",
        'production_home' => "/home/orbit-app-{$project->id}",
    ]);

    expect(fn () => run_legacy_schema_migration($migration, 'down'))
        ->toThrow(RuntimeException::class, 'Cannot discard recorded production AppInstance identity.');

    expect(Schema::hasColumns('instances', ['production_user', 'production_home']))->toBeTrue();
});

function app_instance_production_identity_migration(): object
{
    return require base_path(
        'database/migrations/2026_09_09_060000_add_production_identity_to_app_instances_table.php',
    );
}

/** @return array{Project, Node} */
function production_identity_migration_parents(): array
{
    $node = Node::query()->create([
        'name' => 'production-identity-'.Node::query()->count(),
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.'.(Node::query()->count() + 70),
    ]);
    $project = Project::query()->create([
        'name' => 'Production identity '.Project::query()->count(),
        'slug' => 'production-identity-'.Project::query()->count(),
        'repository_url' => 'https://example.test/production-identity.git',
        'default_branch' => 'main',
        'apps' => fixture_apps('public'),
    ]);

    return [$project, $node];
}
