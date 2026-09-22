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
            $table->boolean('verification_required')->default(false);
            $table->json('verification_criteria')->nullable();
            $table->unsignedBigInteger('review_verification_id')->nullable();
        });
        Schema::create('task_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('attempt');
            $table->uuid('run_key');
            $table->unsignedBigInteger('instance_id');
            $table->unsignedBigInteger('node_id');
            $table->text('checkout_path');
            $table->string('criteria_digest', 64);
            $table->string('policy_version');
            $table->string('status');
            $table->json('references');
            $table->json('result')->nullable();
            $table->json('answers')->nullable();
            $table->string('evaluation_key', 64)->nullable()->index();
            $table->string('model');
            $table->double('threshold');
            $table->unsignedSmallInteger('semantic_attempts')->default(0);
            $table->unsignedBigInteger('semantic_input_tokens')->nullable();
            $table->unsignedInteger('semantic_duration_ms')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['task_id', 'run_key']);
            $table->index(['task_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_verifications');
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn(['verification_required', 'verification_criteria', 'review_verification_id']);
        });
    }
};
