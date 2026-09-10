<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $unsupported = DB::table('app_instances')
            ->where('source_kind', '<>', 'managed_clone')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        if ($unsupported !== []) {
            throw new RuntimeException(
                'Cannot migrate unsupported AppInstance source ownership: '.implode(', ', $unsupported),
            );
        }

        DB::transaction(function (): void {
            Schema::table('apps', static function (Blueprint $table): void {
                $table->renameColumn('main_branch', 'default_branch');
            });
            Schema::table('app_instances', static function (Blueprint $table): void {
                $table->string('source_layout')->default('checkout')->after('source_kind');
                $table->string('branch_override')->nullable()->after('branch');
                $table->boolean('migration_required')->default(false)->after('branch_override');
            });

            DB::table('app_instances')->update(['source_layout' => 'checkout']);
            DB::table('app_instances')
                ->where('name', '<>', 'default')
                ->whereExists(static function (Builder $query): void {
                    $query
                        ->selectRaw('1')
                        ->from('apps')
                        ->whereColumn('apps.id', 'app_instances.app_id')
                        ->whereColumn('apps.default_branch', 'app_instances.name');
                })
                ->update(['migration_required' => true]);
            Schema::table('app_instances', static function (Blueprint $table): void {
                $table->dropColumn('source_kind');
            });

            $this->createLayoutTriggers();
        });
    }

    public function down(): void
    {
        $unsupported = DB::table('app_instances')
            ->where('source_layout', '<>', 'checkout')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        if ($unsupported !== []) {
            throw new RuntimeException(
                'Cannot restore legacy AppInstance source ownership: '.implode(', ', $unsupported),
            );
        }

        DB::transaction(function (): void {
            DB::statement('DROP TRIGGER IF EXISTS app_instances_source_layout_insert');
            DB::statement('DROP TRIGGER IF EXISTS app_instances_source_layout_update');

            Schema::table('app_instances', static function (Blueprint $table): void {
                $table->string('source_kind')->default('managed_clone')->after('environment');
            });
            DB::table('app_instances')->update(['source_kind' => 'managed_clone']);
            Schema::table('app_instances', static function (Blueprint $table): void {
                $table->dropColumn(['branch_override', 'migration_required', 'source_layout']);
            });
            Schema::table('apps', static function (Blueprint $table): void {
                $table->renameColumn('default_branch', 'main_branch');
            });
        });
    }

    private function createLayoutTriggers(): void
    {
        DB::statement(<<<'SQL'
            CREATE TRIGGER app_instances_source_layout_insert
            BEFORE INSERT ON app_instances
            WHEN NEW.source_layout NOT IN ('checkout', 'worktree')
            BEGIN
                SELECT RAISE(ABORT, 'Invalid AppInstance source layout.');
            END
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER app_instances_source_layout_update
            BEFORE UPDATE OF source_layout ON app_instances
            WHEN NEW.source_layout NOT IN ('checkout', 'worktree')
            BEGIN
                SELECT RAISE(ABORT, 'Invalid AppInstance source layout.');
            END
            SQL);
    }
};
