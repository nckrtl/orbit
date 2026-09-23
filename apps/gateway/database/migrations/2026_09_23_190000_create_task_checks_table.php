<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_checks', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_comment_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->unsignedInteger('pid');
            $table->string('process_started');
            $table->string('head_before', 64);
            $table->string('tree_before', 64);
            $table->string('head_after', 64)->nullable();
            $table->string('tree_after', 64)->nullable();
            $table->integer('exit_code')->nullable();
            $table->json('changed_paths')->nullable();
            $table->text('output')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['task_id', 'id']);
        });

        Schema::table('tasks', static function (Blueprint $table): void {
            $table->dropColumn('completion_handoff_check_id');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->string('completion_handoff_check_id')->nullable();
        });
        Schema::dropIfExists('task_checks');
    }
};
