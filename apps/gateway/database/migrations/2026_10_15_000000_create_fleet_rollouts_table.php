<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fleet rollout of ADR 0202: one rollout per desired fleet state, its per-Node results, and the
 * footprint each Node last received.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_rollouts', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('gateway_release_id')->nullable()->unique()->constrained('gateway_releases')->nullOnDelete();
            $table->char('commit', 40)->index();
            $table->string('status', 16)->index();
            $table->json('desired_state');
            $table->json('order');
            $table->foreignId('halted_node_id')->nullable()->constrained('nodes')->nullOnDelete();
            $table->string('error_code')->nullable();
            $table->text('message')->nullable();
            $table->json('alert')->nullable();
            // Non-halting alerts raised once per rollout, such as a skipped Caddyfile.
            $table->json('notices')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('fleet_rollout_nodes', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fleet_rollout_id')->constrained('fleet_rollouts')->cascadeOnDelete();
            $table->foreignId('node_id')->nullable()->constrained('nodes')->nullOnDelete();
            $table->string('node_name');
            $table->unsignedInteger('position');
            $table->string('outcome', 16);
            $table->string('step', 32)->nullable();
            $table->string('error_code')->nullable();
            $table->text('message')->nullable();
            $table->json('evidence')->nullable();
            $table->string('cli_version')->nullable();
            $table->string('agent_version')->nullable();
            $table->char('footprint_digest', 64)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['fleet_rollout_id', 'node_id']);
        });

        Schema::create('node_footprints', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('node_id')->unique()->constrained('nodes')->cascadeOnDelete();
            $table->char('digest', 64)->nullable();
            $table->json('artifacts')->nullable();
            $table->timestamp('converged_at')->nullable();
            // `foreign` while /usr/local/bin/orbit is a CLI Orbit did not install; the Node is left out of the rollout.
            $table->string('cli_state', 16)->nullable();
            $table->timestamp('cli_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_footprints');
        Schema::dropIfExists('fleet_rollout_nodes');
        Schema::dropIfExists('fleet_rollouts');
    }
};
