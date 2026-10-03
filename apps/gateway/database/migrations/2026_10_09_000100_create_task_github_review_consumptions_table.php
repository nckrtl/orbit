<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_github_review_consumptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_id')->constrained('tasks')->cascadeOnDelete();
            $table->string('repository');
            $table->unsignedInteger('pull_request_number');
            $table->unsignedBigInteger('review_id');
            $table->string('identity');
            // A historical window marker, not a live relationship.
            $table->unsignedBigInteger('operator_task_id')->nullable();
            $table->foreignId('fixup_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->string('digest', 64);
            $table->text('packet');
            $table->timestamp('created_at');
            $table->unique(['group_id', 'repository', 'pull_request_number', 'review_id'], 'task_github_review_consumption_source');
        });
        Schema::create('task_github_review_feedback', function (Blueprint $table): void {
            $table->foreignId('group_id')->primary()->constrained('tasks')->cascadeOnDelete();
            $table->json('causes');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_github_review_feedback');
        Schema::dropIfExists('task_github_review_consumptions');
    }
};
