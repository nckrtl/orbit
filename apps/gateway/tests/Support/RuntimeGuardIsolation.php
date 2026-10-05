<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/** Historical migration tests must not retain guards from a later, dependent schema.
 * @return list<object{name: string, sql: string}>
 */
function take_app_runtime_guards(): array
{
    $guards = DB::table('sqlite_master')->where('type', 'trigger')->get(['name', 'sql'])->filter(
        static fn ($guard): bool => str_contains($guard->sql, 'projects.apps')
            || str_contains($guard->sql, 'SELECT apps FROM projects')
            || str_contains($guard->sql, 'app_overrides')
            || str_contains($guard->sql, 'route_ids')
            || str_contains($guard->sql, 'NEW.app ')
            || str_contains($guard->sql, 'existing.app'),
    )->all();
    foreach ($guards as $guard) {
        DB::statement('DROP TRIGGER "'.str_replace('"', '""', $guard->name).'"');
    }

    return array_values($guards);
}

/** @param list<object{name: string, sql: string}> $guards */
function restore_app_runtime_guards(array $guards): void
{
    foreach ($guards as $guard) {
        DB::statement('DROP TRIGGER IF EXISTS "'.str_replace('"', '""', $guard->name).'"');
        DB::statement($guard->sql);
    }
}
