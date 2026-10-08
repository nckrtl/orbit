<?php

declare(strict_types=1);

use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\InstanceEnvironmentValue;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

const RENAME_APP_DOMAIN_MIGRATION = '2026_10_04_000000_rename_app_domain_to_project_and_instance';

/**
 * @param  callable(): void  $callback
 */
function with_rename_migration_database(callable $callback): void
{
    $original = DB::getDefaultConnection();
    config(['database.connections.rename_migration' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);
    DB::setDefaultConnection('rename_migration');

    try {
        $callback();
    } finally {
        DB::setDefaultConnection($original);
        DB::purge('rename_migration');
    }
}

function rename_migration_rows(): object
{
    $node = Node::query()->create([
        'name' => 'rename-node',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.77',
        'wireguard_ip' => '10.44.0.77',
    ]);
    $project = new Project([
        'name' => 'Rename',
        'slug' => 'rename',
        'repository_url' => 'https://example.test/rename.git',
        'default_branch' => 'main',
    ]);
    // These Project settings were added after the rename migration.
    $project->offsetUnset('source_access');
    $project->offsetUnset('task_compute');
    $project->offsetUnset('review_and_merge');
    $project->save();
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'dev',
        'source_layout' => 'checkout',
        'checkout_path' => '/srv/rename',
        'status' => 'active',
    ]);
    InstanceDeployment::query()->create([
        'instance_id' => $instance->id,
        'started_at' => now(),
        'status' => 'succeeded',
    ]);
    InstanceEnvironmentValue::query()->create([
        'instance_id' => $instance->id,
        'env_key' => 'APP_URL',
        'env_value' => 'https://{{instance.domain}}/{{instance.environment}}',
    ]);
    DB::table('activity_log')->insert([
        'description' => 'renamed the domain',
        'subject_type' => Project::class,
        'subject_id' => $project->id,
        'causer_type' => Project::class,
        'causer_id' => $project->id,
        'request_id' => (string) Str::uuid(),
        'command' => 'project:show',
        'status' => 'success',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return (object) [
        'projects' => DB::table('projects')->count(),
        'instances' => DB::table('instances')->count(),
        'deployments' => DB::table('instance_deployments')->count(),
        'environment' => DB::table('instance_environment_values')->count(),
        'activity' => DB::table('activity_log')->count(),
    ];
}

function rename_migration_is_recorded(): bool
{
    return DB::table('migrations')->where('migration', RENAME_APP_DOMAIN_MIGRATION)->exists();
}

function rename_migration_migrate(): void
{
    // Stop at the rename migration. Rollback uses --step=1, so a later
    // migration would be the one reversed and the Project schema would stay.
    $cutoff = RENAME_APP_DOMAIN_MIGRATION.'.php';
    $paths = array_values(array_filter(
        glob(database_path('migrations/*.php')) ?: [],
        static fn (string $path): bool => ! str_contains($path, 'merge_task_groups_into_tasks')
            && basename($path) <= $cutoff,
    ));
    Artisan::call('migrate', ['--path' => $paths, '--realpath' => true, '--force' => true]);
}

it('renames the Project domain in place and rolls it back', function (): void {
    with_rename_migration_database(function (): void {
        rename_migration_migrate();
        $counts = rename_migration_rows();

        Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);

        expect(Schema::hasTable('apps'))->toBeTrue()
            ->and(Schema::hasTable('projects'))->toBeFalse()
            ->and(Schema::hasTable('app_instances'))->toBeTrue()
            ->and(DB::table('apps')->count())->toBe($counts->projects)
            ->and(DB::table('app_instances')->count())->toBe($counts->instances)
            ->and(DB::table('app_instance_deployments')->count())->toBe($counts->deployments)
            ->and(DB::table('app_instance_environment_values')->count())->toBe($counts->environment)
            ->and(DB::table('activity_log')->where('subject_type', 'App\\Models\\App')->count())->toBe(1)
            ->and(DB::table('activity_log')->where('causer_type', 'App\\Models\\App')->count())->toBe(1)
            ->and(Crypt::decrypt(DB::table('app_instance_environment_values')->value('env_value'), false))
            ->toBe('https://{{app_instance.domain}}/{{app_instance.environment}}')
            ->and(collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'index' AND sql IS NOT NULL"))->pluck('name')->all())
            ->toContain('apps_slug_unique', 'app_instances_app_id_name_unique', 'app_instance_removal_members_live_unique')
            ->and(DB::table('migrations')->where('migration', RENAME_APP_DOMAIN_MIGRATION)->exists())->toBeFalse();

        $partial = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'index' AND name = 'app_instance_removal_members_live_unique'");
        expect($partial->sql ?? null)->toContain('WHERE row_deleted_at IS NULL')
            ->and(DB::selectOne("SELECT sql FROM sqlite_master WHERE name = 'route_targets_reject_analytics_tracking'")->sql ?? '')
            ->toContain('App instance targets');

        rename_migration_migrate();

        expect(Schema::hasTable('projects'))->toBeTrue()
            ->and(Schema::hasTable('apps'))->toBeFalse()
            ->and(Schema::hasTable('instances'))->toBeTrue()
            ->and(Schema::hasTable('app_instances'))->toBeFalse()
            ->and(DB::table('projects')->count())->toBe($counts->projects)
            ->and(DB::table('instances')->count())->toBe($counts->instances)
            ->and(DB::table('instance_deployments')->count())->toBe($counts->deployments)
            ->and(DB::table('instance_environment_values')->count())->toBe($counts->environment)
            ->and(DB::select('PRAGMA foreign_key_check'))->toBe([])
            ->and(DB::table('activity_log')->where('subject_type', Project::class)->count())->toBe(1)
            ->and(DB::table('activity_log')->where('causer_type', Project::class)->count())->toBe(1)
            ->and(Crypt::decrypt(DB::table('instance_environment_values')->value('env_value'), false))
            ->toBe('https://{{instance.domain}}/{{instance.environment}}')
            ->and(rename_migration_is_recorded())->toBeTrue();

        $names = collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'index' AND sql IS NOT NULL"))->pluck('name');
        expect($names)->toContain(
            'projects_slug_unique',
            'projects_code_unique',
            'projects_repository_identity_unique',
            'instances_project_id_name_unique',
            'instance_removal_members_live_unique',
            'route_targets_route_id_instance_id_unique',
            'database_connection_targets_instance_id_prefix_unique',
            'process_definitions_project_id_name_unique',
            'schedule_definitions_project_id_name_unique',
            'project_lifecycle_steps_project_id_phase_name_unique',
            'project_node_exclusions_project_id_node_id_unique',
            'task_groups_project_id_status_index',
            'project_updates_project_id_status_index',
            'annotations_instance_id_created_at_index',
            'annotation_events_instance_id_id_index',
        )->and($names)->not->toContain('apps_slug_unique', 'app_instances_app_id_name_unique');

        $partial = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'index' AND name = 'instance_removal_members_live_unique'");
        expect($partial->sql ?? null)->toContain('WHERE row_deleted_at IS NULL');

        foreach (['route_targets_reject_analytics_tracking', 'route_targets_reject_custom_proxy'] as $trigger) {
            expect(DB::selectOne('SELECT sql FROM sqlite_master WHERE name = ?', [$trigger])->sql ?? '')
                ->toContain('Instance targets')
                ->not->toContain('App instance targets');
        }
    });
});

it('leaves the Project schema unchanged when an environment value cannot be decrypted', function (): void {
    with_rename_migration_database(function (): void {
        rename_migration_migrate();
        rename_migration_rows();
        Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);
        DB::table('app_instance_environment_values')->update(['env_value' => 'not-encrypted']);

        expect(fn () => rename_migration_migrate())
            ->toThrow(RuntimeException::class, 'Cannot rewrite Instance environment value');

        expect(Schema::hasTable('apps'))->toBeTrue()
            ->and(Schema::hasTable('projects'))->toBeFalse()
            ->and(Schema::hasTable('app_instances'))->toBeTrue()
            ->and(rename_migration_is_recorded())->toBeFalse();

        expect(fn () => rename_migration_migrate())
            ->toThrow(RuntimeException::class, 'Cannot rewrite Instance environment value');

        expect(Schema::hasTable('apps'))->toBeTrue()
            ->and(Schema::hasTable('projects'))->toBeFalse()
            ->and(rename_migration_is_recorded())->toBeFalse();
    });
});
