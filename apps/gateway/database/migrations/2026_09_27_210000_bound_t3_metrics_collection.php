<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agent_threads')) {
            return;
        }

        Schema::table('agent_threads', static function (Blueprint $table): void {
            $table->timestamp('t3_metrics_collected_at')->nullable();
            $table->timestamp('t3_metrics_final_at')->nullable();
            $table->timestamp('t3_metrics_retry_at')->nullable();
            $table->unsignedInteger('t3_metrics_attempts')->default(0);
            $table->unsignedBigInteger('t3_metrics_activity_version')->default(0);
            $table->unsignedBigInteger('t3_metrics_observed_activity_version')->nullable();
            $table->index(['driver', 't3_metrics_final_at', 't3_metrics_retry_at', 't3_metrics_collected_at'], 'agent_threads_t3_collection_idx');
        });

        Schema::create('agent_thread_send_leases', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_thread_id')->constrained()->cascadeOnDelete();
            $table->uuid('owner_token')->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->index(['agent_thread_id', 'expires_at'], 'agent_thread_send_lease_active_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('agent_threads')) {
            return;
        }

        Schema::dropIfExists('agent_thread_send_leases');

        Schema::table('agent_threads', static function (Blueprint $table): void {
            $table->dropIndex('agent_threads_t3_collection_idx');
            $table->dropColumn([
                't3_metrics_collected_at',
                't3_metrics_final_at',
                't3_metrics_retry_at',
                't3_metrics_attempts',
                't3_metrics_activity_version',
                't3_metrics_observed_activity_version',
            ]);
        });
    }
};
