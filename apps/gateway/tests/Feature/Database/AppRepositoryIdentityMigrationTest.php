<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('reports every duplicate identity before changing the App schema or rows', function (): void {
    $migration = require
        base_path(
            'database/migrations/2026_09_06_000000_add_repository_identity_to_apps.php',
        );
    $migration->down();

    $first = app_repository_identity_legacy_app('first', 'git@github.com:acme/site.git');
    $second = app_repository_identity_legacy_app('second', 'https://github.com/acme/site');
    $third = app_repository_identity_legacy_app('third', 'ssh://git@gitlab.com/acme/api.git');
    $fourth = app_repository_identity_legacy_app('fourth', 'https://gitlab.com/acme/api');
    app_repository_identity_legacy_app('unique', 'https://example.test/acme/unique.git');
    $schemaBefore = app_repository_identity_schema();
    $rowsBefore = app_repository_identity_rows();

    expect(fn () => $migration->up())
        ->toThrow(
            RuntimeException::class,
            "Cannot enforce one App per repository while duplicate identities exist: {$first}, {$second}, {$third}, {$fourth}",
        );

    expect(app_repository_identity_schema())
        ->toBe($schemaBefore)
        ->and(app_repository_identity_rows())
        ->toBe($rowsBefore);
});

it('backfills required unique identities and rolls back only the added boundary', function (): void {
    $migration = require
        base_path(
            'database/migrations/2026_09_06_000000_add_repository_identity_to_apps.php',
        );
    $migration->down();

    $first = app_repository_identity_legacy_app('first', 'git@github.com:acme/site.git');
    $second = app_repository_identity_legacy_app('second', 'https://gitlab.com/acme/api.git');
    $legacyRows = app_repository_identity_rows();

    $migration->up();

    $identities = DB::table('apps')
        ->orderBy('id')
        ->pluck('repository_identity', 'id')
        ->all();
    $identityColumn = collect(DB::select("PRAGMA table_info('apps')"))
        ->first(static fn (object $column): bool => $column->name === 'repository_identity');

    expect($identities)
        ->toBe([
            $first => 'github.com/acme/site',
            $second => 'gitlab.com/acme/api',
        ])
        ->and(Schema::hasColumn('apps', 'repository_identity'))
        ->toBeTrue()
        ->and($identityColumn)
        ->not
        ->toBeNull()
        ->and((int) $identityColumn->notnull)
        ->toBe(1)
        ->and(fn () => DB::table('apps')->insert([
            'name' => 'Duplicate',
            'slug' => 'duplicate',
            'repository_url' => 'ssh://git@github.com/acme/site.git',
            'repository_identity' => 'github.com/acme/site',
            'created_at' => now(),
            'updated_at' => now(),
        ]))
        ->toThrow(QueryException::class);

    $migration->down();

    expect(Schema::hasColumn('apps', 'repository_identity'))
        ->toBeFalse()
        ->and(app_repository_identity_rows())
        ->toBe($legacyRows);
});

function app_repository_identity_legacy_app(string $slug, string $repository): int
{
    return DB::table('apps')->insertGetId([
        'name' => ucfirst($slug),
        'slug' => $slug,
        'repository_url' => $repository,
        'main_branch' => 'main',
        'root' => 'public',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** @return list<array<string, mixed>> */
function app_repository_identity_schema(): array
{
    return collect(DB::select(<<<'SQL'
        SELECT type, name, tbl_name, sql
        FROM sqlite_master
        WHERE type IN ('table', 'index', 'trigger') AND tbl_name = 'apps'
        ORDER BY type, name
        SQL))
        ->map(static fn (object $entry): array => (array) $entry)
        ->all();
}

/** @return list<array<string, mixed>> */
function app_repository_identity_rows(): array
{
    return DB::table('apps')
        ->orderBy('id')
        ->get()
        ->map(static fn (object $row): array => (array) $row)
        ->all();
}
