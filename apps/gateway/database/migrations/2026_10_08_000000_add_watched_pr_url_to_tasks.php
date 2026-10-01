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
            $table->string('watched_pr_completion')->nullable();
            $table->unsignedBigInteger('ended_pr_notice_thread_id')->nullable();
            $table->string('ended_pr_notice_key')->nullable();
            $table->string('ended_pr_notice_state')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn(['watched_pr_url', 'watched_pr_number', 'watched_pr_state', 'watched_pr_completion', 'ended_pr_notice_thread_id', 'ended_pr_notice_key', 'ended_pr_notice_state']);
        });
    }
};
