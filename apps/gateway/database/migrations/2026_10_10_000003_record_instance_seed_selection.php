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
        Schema::table('instances', function (Blueprint $table): void {
            $table->boolean('seed_selected')->default(false);
        });
        // Legacy reservations can already hold a clone even when their prepare response was lost.
        // Preserve every existing selection, including the empty fallback; only new rows select a seed.
        DB::table('instances')->update(['seed_selected' => true]);
    }

    public function down(): void
    {
        Schema::table('instances', function (Blueprint $table): void {
            $table->dropColumn('seed_selected');
        });
    }
};
