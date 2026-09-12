<?php

declare(strict_types=1);

use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('adds nullable clone evidence without changing legacy AppInstances', function (): void {
    $migration = app_instance_clone_migration();
    $migration->down();
    [$app, $node] = clone_migration_parents('legacy');
    $instanceId = DB::table('app_instances')->insertGetId([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'legacy',
        'environment' => 'development',
        'source_layout' => 'checkout',
        'checkout_path' => '/srv/orbit/apps/clone-migration/legacy',
        'migration_required' => false,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $before = (array) DB::table('app_instances')->find($instanceId);

    try {
        $migration->up();

        $after = (array) DB::table('app_instances')->find($instanceId);
        $evidence = array_intersect_key($after, array_flip(app_instance_clone_columns()));
        foreach (app_instance_clone_columns() as $column) {
            unset($after[$column]);
        }

        expect(Schema::hasColumns('app_instances', app_instance_clone_columns()))
            ->toBeTrue()
            ->and($evidence)
            ->toBe(array_fill_keys(app_instance_clone_columns(), null))
            ->and($after)
            ->toBe($before);
    } finally {
        if (! Schema::hasColumn('app_instances', 'clone_candidate_id')) {
            $migration->up();
        }
    }
});

it('persists clone evidence with integer and immutable time casts', function (): void {
    [$app, $candidateNode] = clone_migration_parents('casts-candidate');
    [, $targetNode] = clone_migration_parents('casts-target', $app);
    $candidate = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $candidateNode->id,
        'name' => 'candidate',
        'environment' => 'development',
        'checkout_path' => '/srv/orbit/apps/clone-migration/candidate',
    ]);
    $completedAt = CarbonImmutable::parse('2026-09-12T12:34:56+00:00');

    $target = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $targetNode->id,
        'name' => 'target',
        'environment' => 'production',
        'checkout_path' => '/home/orbit-clone-target',
        'clone_candidate_id' => (string) $candidate->id,
        'clone_candidate_commit' => str_repeat('a', 40),
        'clone_requested_branch' => 'release/one',
        'clone_preview_name' => 'preview',
        'clone_preview_hostname' => 'preview.prod.orbit',
        'clone_sqlite_source_path' => 'database/source.sqlite',
        'clone_completed_at' => $completedAt,
    ])->refresh();

    expect($target->clone_candidate_id)
        ->toBe($candidate->id)
        ->and($target->clone_candidate_commit)
        ->toBe(str_repeat('a', 40))
        ->and($target->clone_requested_branch)
        ->toBe('release/one')
        ->and($target->clone_preview_name)
        ->toBe('preview')
        ->and($target->clone_preview_hostname)
        ->toBe('preview.prod.orbit')
        ->and($target->clone_sqlite_source_path)
        ->toBe('database/source.sqlite')
        ->and($target->clone_completed_at)
        ->toBeInstanceOf(CarbonImmutable::class)
        ->and($target->clone_completed_at?->toIso8601String())
        ->toBe('2026-09-12T12:34:56+00:00');
});

it('refuses rollback before discarding any retained clone evidence', function (array $evidence): void {
    $migration = app_instance_clone_migration();
    [$app, $node] = clone_migration_parents('rollback');
    AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'rollback',
        'environment' => 'production',
        'checkout_path' => '/home/orbit-clone-rollback',
        ...$evidence,
    ]);

    try {
        expect(fn () => $migration->down())
            ->toThrow(RuntimeException::class, 'Cannot discard retained AppInstance clone evidence.');

        expect(Schema::hasColumns('app_instances', app_instance_clone_columns()))->toBeTrue();
    } finally {
        if (! Schema::hasColumn('app_instances', 'clone_candidate_id')) {
            $migration->up();
        }
    }
})->with([
    'candidate identity' => [['clone_candidate_id' => 123]],
    'candidate commit' => [['clone_candidate_commit' => str_repeat('b', 40)]],
    'requested branch' => [['clone_requested_branch' => 'release/two']],
    'preview name' => [['clone_preview_name' => 'preview']],
    'preview hostname' => [['clone_preview_hostname' => 'preview.prod.orbit']],
    'SQLite source path' => [['clone_sqlite_source_path' => 'database/source.sqlite']],
    'completion time' => [['clone_completed_at' => '2026-09-12 12:34:56']],
]);

it('removes only nullable clone columns when rollback is safe', function (): void {
    $migration = app_instance_clone_migration();
    [$app, $node] = clone_migration_parents('safe-down');
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'safe-down',
        'environment' => 'development',
        'checkout_path' => '/srv/orbit/apps/clone-migration/safe-down',
    ]);

    try {
        $migration->down();

        expect(Schema::hasColumns('app_instances', app_instance_clone_columns()))
            ->toBeFalse()
            ->and(DB::table('app_instances')->where('id', $instance->id)->value('name'))
            ->toBe('safe-down');
    } finally {
        if (! Schema::hasColumn('app_instances', 'clone_candidate_id')) {
            $migration->up();
        }
    }
});

function app_instance_clone_migration(): object
{
    return require base_path(
        'database/migrations/2026_09_12_000000_add_clone_evidence_to_app_instances.php',
    );
}

/** @return list<string> */
function app_instance_clone_columns(): array
{
    return [
        'clone_candidate_id',
        'clone_candidate_commit',
        'clone_requested_branch',
        'clone_preview_name',
        'clone_preview_hostname',
        'clone_sqlite_source_path',
        'clone_completed_at',
    ];
}

/** @return array{OrbitApp, Node} */
function clone_migration_parents(string $suffix, ?OrbitApp $app = null): array
{
    $count = Node::query()->count();
    $node = Node::query()->create([
        'name' => "clone-migration-{$suffix}-{$count}",
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.'.(140 + $count),
    ]);
    $app ??= OrbitApp::query()->create([
        'name' => "Clone migration {$suffix}",
        'slug' => "clone-migration-{$suffix}",
        'repository_url' => "https://example.test/clone-migration-{$suffix}.git",
        'default_branch' => 'main',
        'root' => 'public',
    ]);

    return [$app, $node];
}
