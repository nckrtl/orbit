<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('problem_fingerprints', static function (Blueprint $table): void {
            $table->id();
            $table->string('fingerprint', 255)->unique();
            $table->string('source', 16);
            $table->timestamp('first_seen')->nullable();
            $table->timestamp('last_seen')->nullable();
            $table->unsignedInteger('occurrences')->default(0);
            $table->json('evidence');
            $table->foreignId('task_group_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->timestamp('muted_until')->nullable();
            $table->timestamp('filed_at')->nullable();
            $table->timestamps();
            $table->index('source');
        });

        Schema::create('problem_collector_state', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('activity_cursor')->nullable();
            $table->string('log_path', 1024)->nullable();
            $table->unsignedBigInteger('log_inode')->nullable();
            $table->unsignedBigInteger('log_offset')->nullable();
            $table->string('doctor_resume_key', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('problem_fingerprints');
        Schema::dropIfExists('problem_collector_state');
    }
};
