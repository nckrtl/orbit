<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_comments', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_thread_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->text('body');
            $table->string('author');
            $table->unsignedInteger('review_attempt')->nullable();
            $table->string('reviewer_thread_id')->nullable();
            $table->string('driver_turn')->nullable();
            $table->string('commit_sha', 64)->nullable();
            $table->text('pr_url')->nullable();
            $table->timestamp('posted_at');
            $table->timestamps();
            $table->index(['task_id', 'posted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_comments');
    }
};
