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
        Schema::table('app_instance_transfers', static function (Blueprint $table): void {
            $table->json('source_attempt')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('app_instance_transfers')->whereNotNull('source_attempt')->exists()) {
            throw new RuntimeException('Cannot discard captured transfer source evidence.');
        }
        Schema::table('app_instance_transfers', static function (Blueprint $table): void {
            $table->dropColumn('source_attempt');
        });
    }
};
