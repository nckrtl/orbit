<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0165: the per-thread token split beside the cumulative total.
 * Null means that driver did not report the field. Rows stay null until the next observation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_threads', static function (Blueprint $table): void {
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('cached_input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->unsignedBigInteger('model_calls')->nullable();
            $table->unsignedBigInteger('peak_context_tokens')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agent_threads', static function (Blueprint $table): void {
            $table->dropColumn([
                'input_tokens',
                'cached_input_tokens',
                'output_tokens',
                'model_calls',
                'peak_context_tokens',
            ]);
        });
    }
};
