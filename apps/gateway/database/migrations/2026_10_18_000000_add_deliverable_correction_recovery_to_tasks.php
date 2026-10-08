<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->unsignedBigInteger('deliverable_correction_check_id')->nullable();
            $table->json('deliverable_correction_resume')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->dropColumn(['deliverable_correction_check_id', 'deliverable_correction_resume']);
        });
    }
};
