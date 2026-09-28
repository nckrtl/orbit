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
        $instanceColumns = array_values(array_filter(
            ['migration_required', 'registration_migration_recovery'],
            static fn (string $column): bool => Schema::hasColumn('app_instances', $column),
        ));

        if ($instanceColumns !== []) {
            $pending = DB::table('app_instances')
                ->where(function ($query) use ($instanceColumns): void {
                    $first = true;

                    if (in_array('migration_required', $instanceColumns, true)) {
                        $query->where('migration_required', true);
                        $first = false;
                    }

                    if (in_array('registration_migration_recovery', $instanceColumns, true)) {
                        if ($first) {
                            $query->whereNotNull('registration_migration_recovery');
                        } else {
                            $query->orWhereNotNull('registration_migration_recovery');
                        }
                    }
                })
                ->exists();

            if ($pending) {
                throw new RuntimeException('Cannot drop source migration columns while an Instance still records them.');
            }

            Schema::table('app_instances', function (Blueprint $table) use ($instanceColumns): void {
                $table->dropColumn($instanceColumns);
            });
        }

        if (! Schema::hasColumn('apps', 'defaults')) {
            return;
        }

        if (DB::table('apps')->whereNotNull('defaults')->exists()) {
            throw new RuntimeException('Cannot drop Project defaults while a Project still stores them.');
        }

        Schema::table('apps', function (Blueprint $table): void {
            $table->dropColumn('defaults');
        });
    }

    public function down(): void
    {
        Schema::table('app_instances', function (Blueprint $table): void {
            if (! Schema::hasColumn('app_instances', 'migration_required')) {
                $table->boolean('migration_required')->default(false);
            }

            if (! Schema::hasColumn('app_instances', 'registration_migration_recovery')) {
                $table->json('registration_migration_recovery')->nullable();
            }
        });

        if (! Schema::hasColumn('apps', 'defaults')) {
            Schema::table('apps', function (Blueprint $table): void {
                $table->json('defaults')->nullable();
            });
        }
    }
};
