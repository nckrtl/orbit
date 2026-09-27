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
            $table->unsignedBigInteger('t3_input_tokens')->nullable();
            $table->unsignedBigInteger('t3_cached_input_tokens')->nullable();
            $table->unsignedBigInteger('t3_output_tokens')->nullable();
            $table->unsignedBigInteger('t3_model_calls')->nullable();
            $table->unsignedBigInteger('t3_peak_context_tokens')->nullable();
            $table->unsignedBigInteger('t3_counted_total_processed_tokens')->nullable();
            $table->unsignedBigInteger('t3_observed_total_processed_tokens')->nullable();
            $table->unsignedBigInteger('t3_event_sequence')->nullable();
            $table->boolean('t3_metrics_partial')->default(false);
            $table->boolean('t3_metrics_initialized')->default(false);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('agent_threads')) {
            return;
        }

        Schema::table('agent_threads', static function (Blueprint $table): void {
            $table->dropColumn([
                't3_input_tokens', 't3_cached_input_tokens', 't3_output_tokens', 't3_model_calls',
                't3_peak_context_tokens', 't3_counted_total_processed_tokens', 't3_observed_total_processed_tokens',
                't3_event_sequence', 't3_metrics_partial', 't3_metrics_initialized',
            ]);
        });
    }
};
