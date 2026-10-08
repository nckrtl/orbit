<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('task_checks', function (Blueprint $table): void {
            $table->uuid('task_sandbox_id')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('task_checks', function (Blueprint $table): void {
            $table->dropIndex(['task_sandbox_id']);
            $table->dropColumn('task_sandbox_id');
        });
    }
};
