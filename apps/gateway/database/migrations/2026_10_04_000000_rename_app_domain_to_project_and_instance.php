<?php

declare(strict_types=1);

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Renames the Project and Instance domain in place. ALTER TABLE keeps every row
 * and foreign key. SQLite rewrites trigger and index bodies, and this migration
 * recreates trigger and index names that still say the old tables or columns.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const array TABLES = [
        'app_instance_dependency_edges' => 'instance_dependency_edges',
        'app_instance_dependency_observations' => 'instance_dependency_observations',
        'app_instance_dependency_resolutions' => 'instance_dependency_resolutions',
        'app_instance_dependency_scan_attempts' => 'instance_dependency_scan_attempts',
        'app_instance_deploy_steps' => 'instance_deploy_steps',
        'app_instance_deployments' => 'instance_deployments',
        'app_instance_environment_values' => 'instance_environment_values',
        'app_instance_removal_members' => 'instance_removal_members',
        'app_instance_removals' => 'instance_removals',
        'app_instance_transfers' => 'instance_transfers',
        'app_instances' => 'instances',
        'app_updates' => 'project_updates',
        'apps' => 'projects',
    ];

    /** @var array<string, string> */
    private const array COLUMNS = [
        'requested_app_instance_id' => 'requested_instance_id',
        'app_instance_removal_id' => 'instance_removal_id',
        'app_instance_id' => 'instance_id',
        'app_id' => 'project_id',
    ];

    /** @var list<string> */
    private const array TRIGGER_PREFIXES = [
        'app_instance_removal_members_',
        'app_instance_removals_',
        'app_instances_',
        'apps_',
    ];

    /** @var list<string> */
    private const array RENAMED_TRIGGER_PREFIXES = [
        'instance_removal_members_',
        'instance_removals_',
        'instances_',
        'projects_',
    ];

    public function up(): void
    {
        if ($this->isFullyMigrated()) {
            return;
        }

        DB::transaction(function (): void {
            $this->renameSchema(forward: true);
            $this->assertSchemaRenamed();
            $this->rewriteStoredModelClass('App\\Models\\App', 'App\\Models\\Project');
            $this->rewriteEnvironmentPlaceholders(forward: true);
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $this->rewriteEnvironmentPlaceholders(forward: false);
            $this->rewriteStoredModelClass('App\\Models\\Project', 'App\\Models\\App');
            $this->renameSchema(forward: false);
        });
    }

    /**
     * Tests re-run historical migrations against the schema those migrations name.
     * This flips only tables, columns, and triggers, and skips a schema that is already there.
     */
    public function renameSchema(bool $forward): void
    {
        $this->assertSqlite();

        if ($forward) {
            if (Schema::hasTable('apps')) {
                $this->renameTables(forward: true);
                $this->renameColumns(forward: true);
                $this->renameTriggers(forward: true);
            }

            $this->renameIndexes(forward: true);
            $this->rewriteTriggerMessages(forward: true);
            $this->unquoteTriggerIdentifiers();

            return;
        }

        if (! Schema::hasTable('projects')) {
            return;
        }

        $this->rewriteTriggerMessages(forward: false);
        $this->renameIndexes(forward: false);
        $this->renameTriggers(forward: false);
        $this->renameColumns(forward: false);
        $this->renameTables(forward: false);
        $this->unquoteTriggerIdentifiers();
    }

    /**
     * SQLite quotes identifiers it rewrites inside triggers. Historical migrations
     * compare the original unquoted text, so a test flip puts that text back.
     */
    private function unquoteTriggerIdentifiers(): void
    {
        $identifiers = array_unique([
            ...array_keys(self::TABLES),
            ...array_values(self::TABLES),
            ...array_keys(self::COLUMNS),
            ...array_values(self::COLUMNS),
        ]);
        usort($identifiers, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        $triggers = DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND sql IS NOT NULL");

        foreach ($triggers as $trigger) {
            if (! is_string($trigger->name) || ! is_string($trigger->sql)) {
                continue;
            }

            $sql = $trigger->sql;

            foreach ($identifiers as $identifier) {
                $sql = str_replace('"'.$identifier.'"', $identifier, $sql);
            }

            if ($sql === $trigger->sql) {
                continue;
            }

            DB::statement('DROP TRIGGER IF EXISTS "'.str_replace('"', '""', $trigger->name).'"');
            DB::statement($sql);
        }
    }

    private function assertSqlite(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            throw new RuntimeException('The Project and Instance rename supports SQLite only.');
        }
    }

    private function renameTables(bool $forward): void
    {
        foreach (self::TABLES as $from => $to) {
            [$source, $target] = $forward ? [$from, $to] : [$to, $from];

            if (! Schema::hasTable($source)) {
                continue;
            }

            if (Schema::hasTable($target)) {
                throw new RuntimeException("Cannot rename {$source} to {$target} because {$target} already exists.");
            }

            Schema::rename($source, $target);
        }
    }

    private function renameColumns(bool $forward): void
    {
        $columns = $forward ? self::COLUMNS : array_flip(self::COLUMNS);

        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if (str_starts_with($table, 'sqlite_')) {
                continue;
            }

            foreach ($columns as $from => $to) {
                if (! Schema::hasColumn($table, $from) || Schema::hasColumn($table, $to)) {
                    continue;
                }

                Schema::table($table, static function (Blueprint $blueprint) use ($from, $to): void {
                    $blueprint->renameColumn($from, $to);
                });
            }
        }
    }

    private function renameTriggers(bool $forward): void
    {
        $triggers = DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND sql IS NOT NULL ORDER BY name");

        foreach ($triggers as $trigger) {
            if (! is_string($trigger->name) || ! is_string($trigger->sql)) {
                continue;
            }

            $renamed = $this->triggerName($trigger->name, $forward);

            if ($renamed === $trigger->name) {
                continue;
            }

            DB::statement('DROP TRIGGER IF EXISTS "'.$trigger->name.'"');
            DB::statement($this->triggerSql($trigger->sql, $trigger->name, $renamed));
        }
    }

    private function triggerName(string $name, bool $forward): string
    {
        $from = $forward ? self::TRIGGER_PREFIXES : self::RENAMED_TRIGGER_PREFIXES;
        $to = $forward ? self::RENAMED_TRIGGER_PREFIXES : self::TRIGGER_PREFIXES;

        foreach ($from as $index => $prefix) {
            if (str_starts_with($name, $prefix)) {
                return $to[$index].substr($name, strlen($prefix));
            }
        }

        return $name;
    }

    private function triggerSql(string $sql, string $from, string $to): string
    {
        $quoted = preg_quote($from, '/');
        $updated = preg_replace(
            '/\ACREATE TRIGGER\s+(?:"'.$quoted.'"|'.$quoted.'(?![A-Za-z0-9_]))/i',
            'CREATE TRIGGER "'.$to.'"',
            $sql,
            1,
            $count,
        );

        if (! is_string($updated) || $count !== 1) {
            throw new RuntimeException("Cannot recreate trigger {$from}.");
        }

        return $updated;
    }

    private function renameIndexes(bool $forward): void
    {
        $indexes = DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'index' AND sql IS NOT NULL ORDER BY name");

        foreach ($indexes as $index) {
            if (! is_string($index->name) || ! is_string($index->sql) || str_starts_with($index->name, 'sqlite_')) {
                continue;
            }

            $renamed = $this->renameIndexedName($index->name, $forward);

            if ($renamed === $index->name) {
                continue;
            }

            DB::statement('DROP INDEX '.$this->quoteSqlIdentifier($index->name));
            DB::statement($this->renamedIndexSql($index->sql, $index->name, $renamed));
        }
    }

    private function renamedIndexSql(string $sql, string $from, string $to): string
    {
        $quoted = preg_quote($from, '/');
        $updated = preg_replace(
            '/\ACREATE\s+(UNIQUE\s+)?INDEX\s+(?:"'.$quoted.'"|'.$quoted.'(?![A-Za-z0-9_]))/i',
            'CREATE $1INDEX "'.$to.'"',
            $sql,
            1,
            $count,
        );

        if (! is_string($updated) || $count !== 1) {
            throw new RuntimeException("Cannot recreate index {$from}.");
        }

        return $updated;
    }

    private function renameIndexedName(string $name, bool $forward): string
    {
        $map = [...self::TABLES, ...self::COLUMNS];

        if (! $forward) {
            $map = array_flip($map);
        }

        uksort($map, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        foreach ($map as $from => $to) {
            $name = preg_replace(
                '/(?<![A-Za-z0-9])'.preg_quote($from, '/').'(?![A-Za-z0-9])/',
                $to,
                $name,
            ) ?? $name;
        }

        return $name;
    }

    private function rewriteTriggerMessages(bool $forward): void
    {
        [$from, $to] = $forward
            ? [' own App instance targets.', ' own Instance targets.']
            : [' own Instance targets.', ' own App instance targets.'];
        $triggers = DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND sql IS NOT NULL ORDER BY name");

        foreach ($triggers as $trigger) {
            if (! is_string($trigger->name) || ! is_string($trigger->sql) || ! str_contains($trigger->sql, $from)) {
                continue;
            }

            DB::statement('DROP TRIGGER IF EXISTS '.$this->quoteSqlIdentifier($trigger->name));
            DB::statement(str_replace($from, $to, $trigger->sql));
        }
    }

    private function quoteSqlIdentifier(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    private function isFullyMigrated(): bool
    {
        if (! Schema::hasTable('projects') || Schema::hasTable('apps') || Schema::hasTable('app_instances') || ! Schema::hasTable('instances')) {
            return false;
        }

        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if (str_starts_with($table, 'sqlite_')) {
                continue;
            }

            foreach (array_keys(self::COLUMNS) as $column) {
                if (Schema::hasColumn($table, $column)) {
                    return false;
                }
            }
        }

        $indexes = DB::select("SELECT name FROM sqlite_master WHERE type = 'index' AND sql IS NOT NULL");

        foreach ($indexes as $index) {
            if (is_string($index->name) && ! str_starts_with($index->name, 'sqlite_') && $this->renameIndexedName($index->name, true) !== $index->name) {
                return false;
            }
        }

        $triggers = DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND sql IS NOT NULL");

        foreach ($triggers as $trigger) {
            if (! is_string($trigger->name) || ! is_string($trigger->sql)) {
                continue;
            }

            if ($this->triggerName($trigger->name, true) !== $trigger->name || str_contains($trigger->sql, 'App instance targets') || str_contains($trigger->sql, 'app_instances') || str_contains($trigger->sql, 'app_instance_id')) {
                return false;
            }
        }

        foreach ($this->storedModelColumns() as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column) && DB::table($table)->where($column, 'App\\Models\\App')->exists()) {
                    return false;
                }
            }
        }

        return $this->environmentPlaceholdersLack('{{app_instance.domain}}', '{{app_instance.environment}}');
    }

    /**
     * @return array<string, list<string>>
     */
    private function storedModelColumns(): array
    {
        return [
            'activity_log' => ['subject_type', 'causer_type'],
            'processes' => ['owner_type'],
            'schedules' => ['target_type'],
            'task_groups' => ['taskable_type'],
            'settings' => ['scope_type'],
        ];
    }

    private function environmentPlaceholdersLack(string $rejectedDomain, string $rejectedEnvironment): bool
    {
        $table = Schema::hasTable('instance_environment_values')
            ? 'instance_environment_values'
            : 'app_instance_environment_values';

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'env_value')) {
            return true;
        }

        foreach (DB::table($table)->orderBy('id')->get(['env_value']) as $row) {
            if (! is_string($row->env_value) || $row->env_value === '') {
                continue;
            }

            try {
                $plain = Crypt::decrypt($row->env_value, false);
            } catch (DecryptException) {
                return false;
            }

            if (! is_string($plain) || str_contains($plain, $rejectedDomain) || str_contains($plain, $rejectedEnvironment)) {
                return false;
            }
        }

        return true;
    }

    private function assertSchemaRenamed(): void
    {
        $stale = DB::select(<<<'SQL'
            SELECT name FROM sqlite_master
            WHERE type = 'trigger' AND sql IS NOT NULL AND (
                sql LIKE '%app_instances%'
                OR sql LIKE '%app_instance_id%'
                OR sql LIKE '%app_updates%'
                OR sql LIKE '%"apps"%'
                OR sql LIKE '% apps %'
                OR sql LIKE '% apps.%'
                OR sql GLOB '*[^A-Za-z0-9_]app_id[^A-Za-z0-9_]*'
                OR sql LIKE '%App instance targets%'
            )
            SQL);

        if ($stale !== []) {
            $names = [];

            foreach ($stale as $trigger) {
                $row = (array) $trigger;
                $name = $row['name'] ?? null;
                $names[] = is_string($name) ? $name : 'unknown';
            }

            throw new RuntimeException('SQLite triggers still name the App domain: '.implode(', ', $names).'.');
        }

        $violations = DB::select('PRAGMA foreign_key_check');

        if ($violations !== []) {
            throw new RuntimeException('Renaming Project and Instance tables broke a foreign key.');
        }
    }

    private function rewriteStoredModelClass(string $from, string $to): void
    {
        foreach ($this->storedModelColumns() as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column)) {
                    DB::table($table)->where($column, $from)->update([$column => $to]);
                }
            }
        }
    }

    private function rewriteEnvironmentPlaceholders(bool $forward): void
    {
        $table = Schema::hasTable('instance_environment_values')
            ? 'instance_environment_values'
            : 'app_instance_environment_values';

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'env_value')) {
            return;
        }

        [$fromDomain, $toDomain] = $forward
            ? ['{{app_instance.domain}}', '{{instance.domain}}']
            : ['{{instance.domain}}', '{{app_instance.domain}}'];
        [$fromEnvironment, $toEnvironment] = $forward
            ? ['{{app_instance.environment}}', '{{instance.environment}}']
            : ['{{instance.environment}}', '{{app_instance.environment}}'];

        foreach (DB::table($table)->orderBy('id')->get(['id', 'env_value']) as $row) {
            if (! is_string($row->env_value) || $row->env_value === '') {
                continue;
            }

            try {
                $plain = Crypt::decrypt($row->env_value, false);
            } catch (DecryptException $exception) {
                throw new RuntimeException("Cannot rewrite Instance environment value {$row->id}.", previous: $exception);
            }

            if (! is_string($plain)) {
                throw new RuntimeException("Instance environment value {$row->id} is not text.");
            }

            $updated = str_replace([$fromDomain, $fromEnvironment], [$toDomain, $toEnvironment], $plain);

            if ($updated === $plain) {
                continue;
            }

            DB::table($table)->where('id', $row->id)->update([
                'env_value' => Crypt::encrypt($updated, false),
            ]);
        }
    }
};
