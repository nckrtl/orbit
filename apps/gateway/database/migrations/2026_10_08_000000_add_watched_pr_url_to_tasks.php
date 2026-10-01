<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->string('watched_pr_url')->nullable();
            $table->unsignedInteger('watched_pr_number')->nullable();
            $table->string('watched_pr_state')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn(['watched_pr_url', 'watched_pr_number', 'watched_pr_state']);
        });
    }
};
