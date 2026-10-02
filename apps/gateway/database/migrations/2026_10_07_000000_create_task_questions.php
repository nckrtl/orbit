<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The empty question table. The assistance-kind migration writes one escalated row
 * for each open direction request after this table exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('task_questions')) {
            return;
        }

        Schema::create('task_questions', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('subtask_id')->constrained('tasks')->cascadeOnDelete();
            $table->unsignedInteger('attempt');
            $table->string('asked_by');
            $table->text('question');
            $table->string('status');
            $table->string('answered_by')->nullable();
            $table->text('answer')->nullable();
            $table->string('cause')->nullable();
            $table->timestamp('asked_at');
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->unsignedBigInteger('opened_comment_id')->nullable();
            $table->unsignedBigInteger('resolution_comment_id')->nullable();
            $table->unsignedBigInteger('answered_comment_id')->nullable();
            $table->timestamps();

            $table->unique('opened_comment_id');
            $table->index('asked_at');
            $table->index(['status', 'cause']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_questions');
    }
};
