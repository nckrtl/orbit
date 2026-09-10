<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $invalid = DB::table('app_instance_removal_members')
            ->where('environment', 'development')
            ->whereRaw('(starting_commit IS NULL OR length(starting_commit) NOT IN (40, 64))')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        if ($invalid !== []) {
            throw new RuntimeException(
                'Development AppInstance removal members need a valid source commit before upgrade: '
                    .implode(', ', $invalid),
            );
        }

        Schema::table('app_instance_removal_members', static function (Blueprint $table): void {
            $table->string('source_commit', 64)->nullable()->after('starting_commit');
        });

        $this->backfillDevelopmentSourceCommits();

        $this->createSourceCommitTriggers();
    }

    public function down(): void
    {
        $incompatible = DB::table('app_instance_removal_members')
            ->where('environment', 'development')
            ->whereRaw('source_commit IS NOT starting_commit')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        if ($incompatible !== []) {
            throw new RuntimeException(
                'Cannot roll back distinct AppInstance removal source commits: '.implode(', ', $incompatible),
            );
        }

        $this->dropSourceCommitTriggers();

        Schema::table('app_instance_removal_members', static function (Blueprint $table): void {
            $table->dropColumn('source_commit');
        });
    }

    private function createSourceCommitTriggers(): void
    {
        DB::statement(<<<'SQL'
            CREATE TRIGGER app_instance_removal_members_source_commit_insert
            BEFORE INSERT ON app_instance_removal_members
            WHEN NEW.environment = 'development' AND (
                NEW.source_commit IS NULL
                OR length(NEW.source_commit) NOT IN (40, 64)
            )
            BEGIN
                SELECT RAISE(ABORT, 'Invalid AppInstance removal member contract.');
            END
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER app_instance_removal_members_source_commit_immutable
            BEFORE UPDATE ON app_instance_removal_members
            WHEN NEW.source_commit IS NOT OLD.source_commit
            BEGIN
                SELECT RAISE(ABORT, 'Invalid AppInstance removal member contract.');
            END
            SQL);
    }

    private function backfillDevelopmentSourceCommits(): void
    {
        $immutableTrigger = DB::table('sqlite_master')
            ->where('type', 'trigger')
            ->where('name', 'app_instance_removal_members_immutable')
            ->value('sql');

        if (! is_string($immutableTrigger) || trim($immutableTrigger) === '') {
            throw new RuntimeException('The AppInstance removal member immutability trigger is missing.');
        }

        DB::transaction(static function () use ($immutableTrigger): void {
            DB::statement('DROP TRIGGER app_instance_removal_members_immutable');
            DB::table('app_instance_removal_members')
                ->where('environment', 'development')
                ->update(['source_commit' => DB::raw('starting_commit')]);
            DB::unprepared($immutableTrigger);
        });
    }

    private function dropSourceCommitTriggers(): void
    {
        foreach ([
            'app_instance_removal_members_source_commit_insert',
            'app_instance_removal_members_source_commit_immutable',
        ] as $trigger) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }
};
