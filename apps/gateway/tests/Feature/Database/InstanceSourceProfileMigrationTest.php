<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceState;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('adds nullable profile evidence without inferring or changing legacy rows', function (): void {
    $migration = app_instance_source_profile_migration();

    try {
        run_legacy_schema_migration($migration, 'down');
        [$active, $withoutCheckpoint, $phpSelected, $urlConfigured] = legacy_source_profile_rows();
        $before = source_profile_migration_rows();

        run_legacy_schema_migration($migration, 'up');

        $after = source_profile_migration_rows();
        expect(Schema::hasColumn('instances', 'source_is_laravel'))->toBeTrue();
        foreach ([$active, $withoutCheckpoint, $phpSelected, $urlConfigured] as $instance) {
            expect(DB::table('instances')->where('id', $instance->id)->value('source_is_laravel'))->toBeNull();
        }
        $withoutProfile = array_map(static function (array $row): array {
            unset($row['source_is_laravel']);

            return $row;
        }, $after);
        expect($withoutProfile)->toBe($before);
    } finally {
        if (! Schema::hasColumn('instances', 'source_is_laravel')) {
            run_legacy_schema_migration($migration, 'up');
        }
    }
});

it('refuses rollback before discarding non-active retained profile evidence', function (): void {
    $migration = app_instance_source_profile_migration();
    [$phpSelected, $urlConfigured] = complete_retained_source_profile_rows();
    $before = source_profile_migration_rows();

    expect(fn () => run_legacy_schema_migration($migration, 'down'))
        ->toThrow(
            RuntimeException::class,
            "Cannot discard retained AppInstance source profiles: {$phpSelected->id}, {$urlConfigured->id}",
        );

    expect(Schema::hasColumn('instances', 'source_is_laravel'))
        ->toBeTrue()
        ->and(source_profile_migration_rows())
        ->toBe($before);
});

it('allows rollback when complete profile evidence belongs only to Active rows', function (): void {
    $migration = app_instance_source_profile_migration();
    [$active] = complete_retained_source_profile_rows(InstanceState::Active);
    $before = $active->getAttributes();

    try {
        run_legacy_schema_migration($migration, 'down');

        unset($before['source_is_laravel']);
        expect(Schema::hasColumn('instances', 'source_is_laravel'))
            ->toBeFalse()
            ->and((array) DB::table('instances')->find($active->id))
            ->toBe($before);
    } finally {
        if (! Schema::hasColumn('instances', 'source_is_laravel')) {
            run_legacy_schema_migration($migration, 'up');
        }
    }
});

function app_instance_source_profile_migration(): object
{
    return require base_path(
        'database/migrations/2026_09_09_015031_add_source_profile_to_app_instances_table.php',
    );
}

/** @return array{Instance, Instance, Instance, Instance} */
function legacy_source_profile_rows(): array
{
    return [
        source_profile_migration_instance('active', InstanceState::Active, 'active', '8.5'),
        source_profile_migration_instance('no-checkpoint', InstanceState::SourceResolved, null, null),
        source_profile_migration_instance('php-selected', InstanceState::SourceResolved, 'php-selected', '8.5'),
        source_profile_migration_instance('url-configured', InstanceState::SourceResolved, 'url-configured', null),
    ];
}

/** @return array{Instance, Instance} */
function complete_retained_source_profile_rows(
    InstanceState $status = InstanceState::SourceResolved,
): array {
    $phpSelected = source_profile_migration_instance('complete-php', $status, 'php-selected', '8.5');
    $urlConfigured = source_profile_migration_instance('complete-url', $status, 'url-configured', null);
    $phpSelected->update(['source_is_laravel' => true]);
    $urlConfigured->update(['source_is_laravel' => false]);

    return [$phpSelected->refresh(), $urlConfigured->refresh()];
}

function source_profile_migration_instance(
    string $name,
    InstanceState $status,
    ?string $checkpoint,
    ?string $phpVersion,
): Instance {
    $node = Node::query()->create([
        'name' => "profile-{$name}",
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => "{$name}.example.test",
    ]);
    $project = Project::query()->create([
        'name' => "Profile {$name}",
        'slug' => "profile-{$name}",
        'repository_url' => "https://example.test/{$name}.git",
        'default_branch' => 'main',
        'apps' => fixture_apps('public'),
    ]);

    return Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => $name,
        'checkout_path' => "/srv/{$name}",
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => $phpVersion,
        'provisioning_step' => $checkpoint,
        'status' => $status,
    ]);
}

/** @return list<array<string, mixed>> */
function source_profile_migration_rows(): array
{
    return DB::table('instances')
        ->orderBy('id')
        ->get()
        ->map(
            static fn (object $row): array => (array) $row,
        )
        ->all();
}
