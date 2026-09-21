<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_groups', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_id')->constrained()->restrictOnDelete();
            $table->nullableMorphs('taskable');
            $table->string('title');
            $table->text('brief');
            $table->string('status')->default('queued');
            $table->string('reviewer_thread_id')->nullable();
            $table->string('pr_url')->nullable();
            $table->boolean('notify_coder')->default(false);
            $table->string('implementer_model')->default('gpt-5.6-luna');
            $table->string('reviewer_model')->default('claude-opus-5');
            $table->unsignedInteger('tokens')->nullable();
            $table->integer('line_diff')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->index(['app_id', 'status']);
            $table->index('status');
        });

        Schema::create('tasks', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_group_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('title');
            $table->text('brief');
            $table->string('status')->default('pending');
            $table->string('implementer_thread_id')->nullable();
            $table->unsignedInteger('tokens')->nullable();
            $table->integer('line_diff')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->unique(['task_group_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('task_groups');
    }
};
