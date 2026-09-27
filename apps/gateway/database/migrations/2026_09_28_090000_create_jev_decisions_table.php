<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jev_decisions', function (Blueprint $table): void {
            $table->id();
            $table->string('purpose');
            $table->timestamp('call_started_at');
            $table->foreignId('task_group_id')->nullable()->constrained('task_groups')->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->json('task_ids')->nullable();
            $table->unsignedBigInteger('approval_comment_id')->nullable();
            $table->json('approval_changes')->nullable();
            $table->char('approval_changes_digest', 64)->nullable();
            $table->boolean('approval_changes_redacted')->nullable();
            $table->unsignedInteger('merged_pull_request_number')->nullable();
            $table->string('merge_commit_sha', 40)->nullable();
            $table->timestamp('merged_at')->nullable();
            $table->json('merge_changes')->nullable();
            $table->char('merge_changes_digest', 64)->nullable();
            $table->boolean('merge_changes_redacted')->nullable();
            $table->char('merge_body_digest', 64)->nullable();
            $table->string('agent_thread_id')->nullable();
            $table->json('questions');
            $table->json('input_state');
            $table->json('answers')->nullable();
            $table->json('labels')->nullable();
            $table->string('provider_model')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->timestamps();
            $table->index(['purpose', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jev_decisions');
    }
};
