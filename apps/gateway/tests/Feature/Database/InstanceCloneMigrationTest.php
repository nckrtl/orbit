<?php

declare(strict_types=1);

use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(fn () => roll_back_app_instance_environment_for_migration_test());
afterEach(fn () => restore_app_instance_environment_schema_for_migration_test());

it('adds nullable clone evidence without changing legacy Instances', function (): void {
    $migration = app_instance_clone_migration();
    run_legacy_schema_migration($migration, 'down');
    [$project, $node] = clone_migration_parents('legacy');
    $instanceId = DB::table('instances')->insertGetId([
        'project_id' => $project->id,
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
    $before = (array) DB::table('instances')->find($instanceId);

    try {
        run_legacy_schema_migration($migration, 'up');

        $after = (array) DB::table('instances')->find($instanceId);
        $isolationColumns = app_instance_clone_isolation_columns();
        $evidence = array_intersect_key($after, array_flip($isolationColumns));
        foreach ($isolationColumns as $column) {
            unset($after[$column]);
        }

        expect(Schema::hasColumns('instances', $isolationColumns))
            ->toBeTrue()
            ->and($evidence)
            ->toBe(array_fill_keys($isolationColumns, null))
            ->and($after)
            ->toBe($before);
    } finally {
        if (! Schema::hasColumn('instances', 'clone_candidate_id')) {
            run_legacy_schema_migration($migration, 'up');
        }
    }
});

it('persists clone evidence with integer and immutable time casts', function (): void {
    [$project, $candidateNode] = clone_migration_parents('casts-candidate');
    [, $targetNode] = clone_migration_parents('casts-target', $project);
    $candidate = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $candidateNode->id,
        'name' => 'candidate',
        'environment' => 'development',
        'checkout_path' => '/srv/orbit/apps/clone-migration/candidate',
    ]);
    $completedAt = CarbonImmutable::parse('2026-09-12T12:34:56+00:00');

    $target = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $targetNode->id,
        'name' => 'target',
        'environment' => 'production',
        'checkout_path' => '/home/orbit-clone-target',
        'clone_candidate_id' => (string) $candidate->id,
        'clone_candidate_commit' => str_repeat('a', 40),
        'clone_requested_branch' => 'release/one',
        'clone_preview_name' => 'preview',
        'clone_preview_domain' => 'preview.prod.orbit',
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
        ->and($target->clone_preview_domain)
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
    [$project, $node] = clone_migration_parents('rollback');
    Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'rollback',
        'environment' => 'production',
        'checkout_path' => '/home/orbit-clone-rollback',
        ...$evidence,
    ]);

    try {
        expect(fn () => run_legacy_schema_migration($migration, 'down'))
            ->toThrow(RuntimeException::class, 'Cannot discard retained AppInstance clone evidence.');

        expect(Schema::hasColumns('instances', app_instance_clone_columns()))->toBeTrue();
    } finally {
        if (! Schema::hasColumn('instances', 'clone_candidate_id')) {
            run_legacy_schema_migration($migration, 'up');
        }
    }
})->with([
    'candidate identity' => [['clone_candidate_id' => 123]],
    'candidate commit' => [['clone_candidate_commit' => str_repeat('b', 40)]],
    'requested branch' => [['clone_requested_branch' => 'release/two']],
    'preview name' => [['clone_preview_name' => 'preview']],
    'preview domain' => [['clone_preview_domain' => 'preview.prod.orbit']],
    'SQLite source path' => [['clone_sqlite_source_path' => 'database/source.sqlite']],
    'completion time' => [['clone_completed_at' => '2026-09-12 12:34:56']],
]);

it('removes only nullable clone columns when rollback is safe', function (): void {
    $migration = app_instance_clone_migration();
    [$project, $node] = clone_migration_parents('safe-down');
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'safe-down',
        'environment' => 'development',
        'checkout_path' => '/srv/orbit/apps/clone-migration/safe-down',
    ]);

    try {
        run_legacy_schema_migration($migration, 'down');

        expect(Schema::hasColumns('instances', app_instance_clone_columns()))
            ->toBeFalse()
            ->and(DB::table('instances')->where('id', $instance->id)->value('name'))
            ->toBe('safe-down');
    } finally {
        if (! Schema::hasColumn('instances', 'clone_candidate_id')) {
            run_legacy_schema_migration($migration, 'up');
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
function app_instance_clone_isolation_columns(): array
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

/** @return list<string> */
function app_instance_clone_columns(): array
{
    return [
        'clone_candidate_id',
        'clone_candidate_commit',
        'clone_requested_branch',
        'clone_preview_name',
        'clone_preview_domain',
        'clone_sqlite_source_path',
        'clone_completed_at',
    ];
}

/** @return array{Project, Node} */
function clone_migration_parents(string $suffix, ?Project $project = null): array
{
    $count = Node::query()->count();
    $node = Node::query()->create([
        'name' => "clone-migration-{$suffix}-{$count}",
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.'.(140 + $count),
    ]);
    $project ??= Project::query()->create([
        'name' => "Clone migration {$suffix}",
        'slug' => "clone-migration-{$suffix}",
        'repository_url' => "https://example.test/clone-migration-{$suffix}.git",
        'default_branch' => 'main',
        'apps' => fixture_apps('public'),
    ]);

    return [$project, $node];
}
