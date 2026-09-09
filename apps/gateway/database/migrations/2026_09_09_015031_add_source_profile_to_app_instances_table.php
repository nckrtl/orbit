<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('app_instances', function (Blueprint $table) {
            $table->boolean('source_is_laravel')->nullable()->after('selected_php_version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $retained = DB::table('app_instances')
            ->where('status', '<>', 'active')
            ->whereIn('provisioning_step', ['php-selected', 'url-configured'])
            ->whereNotNull('source_is_laravel')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        if ($retained !== []) {
            throw new RuntimeException(
                'Cannot discard retained AppInstance source profiles: '.implode(', ', $retained),
            );
        }

        Schema::table('app_instances', function (Blueprint $table) {
            $table->dropColumn('source_is_laravel');
        });
    }
};
