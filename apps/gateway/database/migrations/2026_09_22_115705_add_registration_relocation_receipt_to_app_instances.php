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
            $table->json('registration_relocation_receipt')->nullable()->after('registration_source_inode');
        });
    }

    public function down(): void
    {
        if (DB::table('app_instances')->whereNotNull('registration_relocation_receipt')->exists()) {
            throw new RuntimeException('Cannot discard retained registration relocation ownership receipts.');
        }

        Schema::table('app_instances', static function (Blueprint $table): void {
            $table->dropColumn('registration_relocation_receipt');
        });
    }
};
