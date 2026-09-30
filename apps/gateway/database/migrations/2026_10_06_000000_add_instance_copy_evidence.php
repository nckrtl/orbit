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
        Schema::table('instances', static function (Blueprint $table): void {
            $table->string('creation')->default('repository');
            $table->string('copy_mode')->nullable();
            $table->unsignedBigInteger('source_instance_id')->nullable()->index();
        });

        DB::table('instances')
            ->whereNotNull('clone_candidate_id')
            ->update(['creation' => 'clone']);
        DB::table('instances')
            ->whereNotNull('registration_completed_at')
            ->whereNull('clone_candidate_id')
            ->update(['creation' => 'register']);
    }

    public function down(): void
    {
        if (DB::table('instances')->whereNotNull('copy_mode')->orWhereNotNull('source_instance_id')->exists()) {
            throw new RuntimeException('Cannot discard retained Instance copy evidence.');
        }

        Schema::table('instances', static function (Blueprint $table): void {
            $table->dropIndex(['source_instance_id']);
            $table->dropColumn(['creation', 'copy_mode', 'source_instance_id']);
        });
    }
};
