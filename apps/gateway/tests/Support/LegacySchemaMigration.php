<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Historical migrations name the Project domain. Re-running one on the renamed schema
 * flips back to those names for the call, then restores Project and Instance.
 */
function rewrite_current_schema_sql(string $sql, bool $hasEnvironment): string
{
    $replacements = [
        'app_instance_environment_values' => 'instance_environment_values',
        'app_instance_id' => 'instance_id',
        'app_instances' => 'instances',
        'app_id' => 'project_id',
    ];

    foreach ($replacements as $old => $new) {
        $sql = preg_replace(
            '/(?<![A-Za-z0-9_])'.preg_quote($old, '/').'(?![A-Za-z0-9_])/',
            $new,
            $sql,
        ) ?? $sql;
    }

    if (! $hasEnvironment) {
        $sql = str_replace(', environment ON instances', ' ON instances', $sql);
        $sql = str_replace("OR NEW.environment <> 'production'", '', $sql);
        $sql = str_replace("OR app_instances.environment <> 'production'", '', $sql);
        $sql = str_replace("OR instances.environment <> 'production'", '', $sql);
        $sql = str_replace(
            "(SELECT environment FROM instances WHERE id = NEW.instance_id) <> 'production'",
            '0',
            $sql,
        );
    }

    return $sql;
}

function run_legacy_schema_migration(object $migration, string $direction): mixed
{
    $file = (new ReflectionObject($migration))->getFileName() ?: '';
    $isRename = str_contains($file, 'rename_app_domain_to_project_and_instance.php');
    $runsOnCurrentSchema = str_contains($file, 'replace_application_hostnames_with_route_domains.php')
        || str_contains($file, 'drop_public_publication_from_routes.php');
    $rename = require database_path('migrations/2026_10_04_000000_rename_app_domain_to_project_and_instance.php');
    $flipped = false;
    // Earlier migrations still read the Project and Instance roots that named apps replaced. The
    // restored columns stay for the rest of the test; its database transaction discards them.
    if (! $isRename && ! $runsOnCurrentSchema && Schema::hasTable('projects') && ! Schema::hasColumn('projects', 'root')) {
        restore_legacy_project_roots();
    }

    if (! $isRename && ! $runsOnCurrentSchema && Schema::hasTable('projects') && ! Schema::hasTable('apps')) {
        $rename->renameSchema(false);
        $flipped = true;
    }

    $rewrite = $runsOnCurrentSchema && Schema::hasTable('instances') && ! Schema::hasTable('app_instances');
    $hasEnvironment = $rewrite && Schema::hasColumn('instances', 'environment');
    if ($rewrite) {
        DB::connection()->beforeExecuting(static function (string &$query) use (&$rewrite, $hasEnvironment): void {
            if (! $rewrite) {
                return;
            }

            $query = rewrite_current_schema_sql($query, $hasEnvironment);
        });
    }

    try {
        return $migration->{$direction}();
    } finally {
        $rewrite = false;
        if ($flipped && Schema::hasTable('apps') && ! Schema::hasTable('projects')) {
            $rename->renameSchema(true);
        }
    }
}

/** Puts back the Project and Instance roots, and the removal guard that reads them, for historical migrations. */
function restore_legacy_project_roots(): void
{
    (require database_path('migrations/2026_10_21_000005_remove_project_and_instance_roots.php'))->down();
}
