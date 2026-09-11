<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedules', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('target_type');
            $table->unsignedBigInteger('target_id');
            $table->foreignId('host_node_id')->constrained('nodes')->restrictOnDelete();
            $table->string('name', 63);
            $table->string('calendar', 255);
            $table->text('command');
            $table->unsignedInteger('timeout_seconds')->default(3600);
            $table->string('desired_timer_state');
            $table->string('status')->default('provisioning')->index();
            $table->string('failed_step')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_run_status')->nullable();
            $table->timestamps();

            $table->index(['target_type', 'target_id']);
            $table->unique(['target_type', 'target_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
