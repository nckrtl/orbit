<?php

declare(strict_types=1);

use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('adds nullable production identity and enforces one production placement per App and Node', function (): void {
    $migration = app_instance_production_identity_migration();
    $migration->down();
    [$app, $node] = production_identity_migration_parents();
    $firstId = DB::table('app_instances')->insertGetId([
        'app_id' => $app->id,
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
        $migration->up();

        expect(Schema::hasColumns('app_instances', ['production_user', 'production_home']))
            ->toBeTrue()
            ->and(DB::table('app_instances')->find($firstId)->production_user)
            ->toBeNull();

        $development = AppInstance::query()->create([
            'app_id' => $app->id,
            'node_id' => $node->id,
            'name' => 'development',
            'environment' => 'development',
            'checkout_path' => '/srv/development',
        ]);

        expect($development->exists)
            ->toBeTrue()
            ->and(fn () => AppInstance::query()->create([
                'app_id' => $app->id,
                'node_id' => $node->id,
                'name' => 'second',
                'environment' => 'production',
                'checkout_path' => '/home/orbit-app-1-second',
            ]))
            ->toThrow(QueryException::class);
    } finally {
        if (! Schema::hasColumn('app_instances', 'production_user')) {
            $migration->up();
        }
    }
});

it('refuses rollback before discarding recorded production identity', function (): void {
    $migration = app_instance_production_identity_migration();
    [$app, $node] = production_identity_migration_parents();
    AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'production',
        'environment' => 'production',
        'checkout_path' => "/home/orbit-app-{$app->id}",
        'production_user' => "orbit-app-{$app->id}",
        'production_home' => "/home/orbit-app-{$app->id}",
    ]);

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Cannot discard recorded production AppInstance identity.');

    expect(Schema::hasColumns('app_instances', ['production_user', 'production_home']))->toBeTrue();
});

function app_instance_production_identity_migration(): object
{
    return require
        base_path(
            'database/migrations/2026_09_09_060000_add_production_identity_to_app_instances_table.php',
        );
}

/** @return array{OrbitApp, Node} */
function production_identity_migration_parents(): array
{
    $node = Node::query()->create([
        'name' => 'production-identity-'.Node::query()->count(),
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.'.(Node::query()->count() + 70),
    ]);
    $app = OrbitApp::query()->create([
        'name' => 'Production identity '.OrbitApp::query()->count(),
        'slug' => 'production-identity-'.OrbitApp::query()->count(),
        'repository_url' => 'https://example.test/production-identity.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);

    return [$app, $node];
}
