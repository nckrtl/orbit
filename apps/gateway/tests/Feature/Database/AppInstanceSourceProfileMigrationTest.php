<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceState;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('adds nullable profile evidence without inferring or changing legacy rows', function (): void {
    $migration = app_instance_source_profile_migration();
    $routeMigration = route_hostname_change_state_migration();
    $routeMigration->down();

    try {
        $migration->down();
        [$active, $withoutCheckpoint, $phpSelected, $urlConfigured] = legacy_source_profile_rows();
        $before = source_profile_migration_rows();

        $migration->up();

        $after = source_profile_migration_rows();
        expect(Schema::hasColumn('app_instances', 'source_is_laravel'))->toBeTrue();
        foreach ([$active, $withoutCheckpoint, $phpSelected, $urlConfigured] as $instance) {
            expect(DB::table('app_instances')->where('id', $instance->id)->value('source_is_laravel'))->toBeNull();
        }
        $withoutProfile = array_map(static function (array $row): array {
            unset($row['source_is_laravel']);

            return $row;
        }, $after);
        expect($withoutProfile)->toBe($before);
    } finally {
        if (! Schema::hasColumn('app_instances', 'source_is_laravel')) {
            $migration->up();
        }
        $routeMigration->up();
    }
});

it('refuses rollback before discarding non-active retained profile evidence', function (): void {
    $migration = app_instance_source_profile_migration();
    $routeMigration = route_hostname_change_state_migration();
    $routeMigration->down();

    try {
        [$phpSelected, $urlConfigured] = complete_retained_source_profile_rows();
        $before = source_profile_migration_rows();

        expect(fn () => $migration->down())
            ->toThrow(
                RuntimeException::class,
                "Cannot discard retained AppInstance source profiles: {$phpSelected->id}, {$urlConfigured->id}",
            );

        expect(Schema::hasColumn('app_instances', 'source_is_laravel'))
            ->toBeTrue()
            ->and(source_profile_migration_rows())
            ->toBe($before);
    } finally {
        $routeMigration->up();
    }
});

it('allows rollback when complete profile evidence belongs only to Active rows', function (): void {
    $migration = app_instance_source_profile_migration();
    $routeMigration = route_hostname_change_state_migration();
    $routeMigration->down();
    [$active] = complete_retained_source_profile_rows(AppInstanceState::Active);
    $before = $active->getAttributes();

    try {
        $migration->down();

        unset($before['source_is_laravel']);
        expect(Schema::hasColumn('app_instances', 'source_is_laravel'))
            ->toBeFalse()
            ->and((array) DB::table('app_instances')->find($active->id))
            ->toBe($before);
    } finally {
        if (! Schema::hasColumn('app_instances', 'source_is_laravel')) {
            $migration->up();
        }
        $routeMigration->up();
    }
});

function app_instance_source_profile_migration(): object
{
    return require base_path(
        'database/migrations/2026_09_09_015031_add_source_profile_to_app_instances_table.php',
    );
}

function route_hostname_change_state_migration(): object
{
    return require base_path(
        'database/migrations/2026_09_09_070000_add_hostname_change_state_to_routes_table.php',
    );
}

/** @return array{AppInstance, AppInstance, AppInstance, AppInstance} */
function legacy_source_profile_rows(): array
{
    return [
        source_profile_migration_instance('active', AppInstanceState::Active, 'active', '8.5'),
        source_profile_migration_instance('no-checkpoint', AppInstanceState::SourceResolved, null, null),
        source_profile_migration_instance('php-selected', AppInstanceState::SourceResolved, 'php-selected', '8.5'),
        source_profile_migration_instance('url-configured', AppInstanceState::SourceResolved, 'url-configured', null),
    ];
}

/** @return array{AppInstance, AppInstance} */
function complete_retained_source_profile_rows(
    AppInstanceState $status = AppInstanceState::SourceResolved,
): array {
    $phpSelected = source_profile_migration_instance('complete-php', $status, 'php-selected', '8.5');
    $urlConfigured = source_profile_migration_instance('complete-url', $status, 'url-configured', null);
    $phpSelected->update(['source_is_laravel' => true]);
    $urlConfigured->update(['source_is_laravel' => false]);

    return [$phpSelected->refresh(), $urlConfigured->refresh()];
}

function source_profile_migration_instance(
    string $name,
    AppInstanceState $status,
    ?string $checkpoint,
    ?string $phpVersion,
): AppInstance {
    $node = Node::query()->create([
        'name' => "profile-{$name}",
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => "{$name}.example.test",
    ]);
    $app = OrbitApp::query()->create([
        'name' => "Profile {$name}",
        'slug' => "profile-{$name}",
        'repository_url' => "https://example.test/{$name}.git",
        'default_branch' => 'main',
        'root' => 'public',
    ]);

    return AppInstance::query()->create([
        'app_id' => $app->id,
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
    return DB::table('app_instances')
        ->orderBy('id')
        ->get()
        ->map(
            static fn (object $row): array => (array) $row,
        )
        ->all();
}
