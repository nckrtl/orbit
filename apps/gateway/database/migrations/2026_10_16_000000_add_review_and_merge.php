<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0203: the Project switch, the incoming pull request branch and merge state on a task,
 * and the commits Orbit fully reviewed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->boolean('review_and_merge')->default(false);
            $table->string('merge_check', 255)->nullable();
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->string('pr_branch', 255)->nullable();
            $table->string('merge_status', 16)->nullable();
            $table->text('merge_reason')->nullable();
            $table->string('merged_sha', 64)->nullable();
        });

        Schema::create('task_reviewed_commits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->string('sha', 64);
            $table->string('source', 32);
            $table->foreignId('review_task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->timestamp('pushed_at')->nullable();
            $table->unsignedBigInteger('github_review_id')->nullable();
            $table->timestamps();
            $table->unique(['task_id', 'sha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_reviewed_commits');
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn(['pr_branch', 'merge_status', 'merge_reason', 'merged_sha']);
        });
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn(['review_and_merge', 'merge_check']);
        });
    }
};
