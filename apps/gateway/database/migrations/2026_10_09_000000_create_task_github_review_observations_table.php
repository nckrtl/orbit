<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_github_review_scans', function (Blueprint $table): void {
            $table->foreignId('group_id')->primary()->constrained('tasks')->cascadeOnDelete();
            $table->unsignedBigInteger('sequence')->default(0);
            $table->json('snapshot');
        });
        Schema::create('task_github_review_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_id')->constrained('tasks')->cascadeOnDelete();
            $table->string('repository');
            $table->unsignedInteger('pull_request_number');
            $table->unsignedBigInteger('reviewer_id');
            $table->unsignedBigInteger('review_id');
            $table->json('source');
            $table->json('latest');
            $table->unique(['group_id', 'repository', 'pull_request_number', 'review_id'], 'task_github_approval_source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_github_review_observations');
        Schema::dropIfExists('task_github_review_scans');
    }
};
