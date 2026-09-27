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
            $table->foreignId('task_group_id')->nullable()->constrained('task_groups')->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->json('task_ids')->nullable();
            $table->string('agent_thread_id')->nullable();
            $table->json('questions');
            $table->json('input_state');
            $table->json('answers')->nullable();
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
