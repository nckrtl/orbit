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
        Schema::table('app_instances', static function (Blueprint $table): void {
            $table->string('production_php_service', 128)->nullable()->after('production_home');
            $table->string('production_php_pool', 64)->nullable()->after('production_php_service');
            $table->text('production_php_socket')->nullable()->after('production_php_pool');
        });
    }

    public function down(): void
    {
        if (
            DB::table('app_instances')
                ->whereNotNull('production_php_service')
                ->orWhereNotNull('production_php_pool')
                ->orWhereNotNull('production_php_socket')
                ->exists()
        ) {
            throw new RuntimeException('Cannot discard recorded production PHP runtime identity.');
        }

        Schema::table('app_instances', static function (Blueprint $table): void {
            $table->dropColumn([
                'production_php_service',
                'production_php_pool',
                'production_php_socket',
            ]);
        });
    }
};
