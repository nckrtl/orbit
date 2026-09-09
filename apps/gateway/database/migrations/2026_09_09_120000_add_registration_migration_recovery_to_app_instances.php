<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('app_instances', static function (Blueprint $table): void {
            $table->json('registration_migration_recovery')->nullable()->after('registration_source_inode');
        });
    }

    public function down(): void
    {
        $recovering = DB::table('app_instances')
            ->whereNotNull('registration_migration_recovery')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        if ($recovering !== []) {
            throw new RuntimeException(
                'Cannot roll back while AppInstance migration recovery is incomplete: '.implode(', ', $recovering),
            );
        }

        Schema::table('app_instances', static function (Blueprint $table): void {
            $table->dropColumn('registration_migration_recovery');
        });
    }
};
