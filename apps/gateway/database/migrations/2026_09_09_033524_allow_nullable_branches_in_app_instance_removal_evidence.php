<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->replaceInsertGuard(
            '/OR NEW\.root IS NULL\s+OR NEW\.branch IS NULL\s+OR NEW\.starting_commit IS NULL/',
            "OR NEW.root IS NULL\n                OR NEW.starting_commit IS NULL",
        );
    }

    public function down(): void
    {
        $incompatible = DB::table('app_instance_removal_members')
            ->where('environment', 'development')
            ->whereNull('branch')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        if ($incompatible !== []) {
            throw new RuntimeException(
                'Cannot roll back detached AppInstance removal evidence: '.implode(', ', $incompatible),
            );
        }

        $this->replaceInsertGuard(
            '/OR NEW\.root IS NULL\s+OR NEW\.starting_commit IS NULL/',
            "OR NEW.root IS NULL\n                OR NEW.branch IS NULL\n                OR NEW.starting_commit IS NULL",
        );
    }

    private function replaceInsertGuard(string $pattern, string $replacement): void
    {
        $trigger = DB::table('sqlite_master')
            ->where('type', 'trigger')
            ->where('name', 'app_instance_removal_members_insert')
            ->value('sql');

        if (
            ! is_string($trigger)
            || preg_match_all($pattern, $trigger) !== 1
        ) {
            throw new RuntimeException('The AppInstance removal member insert guard is incompatible.');
        }

        $updated = preg_replace($pattern, $replacement, $trigger);

        if (! is_string($updated)) {
            throw new RuntimeException('The AppInstance removal member insert guard cannot be updated.');
        }

        DB::transaction(static function () use ($updated): void {
            DB::statement('DROP TRIGGER app_instance_removal_members_insert');
            DB::unprepared($updated);
        });
    }
};
