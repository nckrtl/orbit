<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instance_app_updates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('instance_id');
            $table->string('fingerprint');
            $table->json('request');
            $table->json('previous_overrides');
            $table->string('phase')->default('reserved');
            $table->timestamp('published_at')->nullable();
            $table->json('completion')->nullable();
            $table->timestamps();
            $table->index(['instance_id', 'phase']);
        });
        Schema::create('instance_app_projections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('instance_id');
            $table->foreignId('active_instance_id')->nullable()->unique()->constrained('instances')->restrictOnDelete();
            $table->foreignId('project_update_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('instance_app_update_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('node_id');
            $table->json('plan');
            $table->string('plan_digest');
            $table->string('render_side')->default('before');
            $table->string('recovery_direction')->nullable();
            $table->json('completion')->nullable();
            $table->timestamps();
            $table->unique(['project_update_id', 'instance_id']);
            $table->unique(['instance_app_update_id', 'instance_id']);
        });
        Schema::create('instance_app_projection_steps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('instance_app_projection_id')->constrained()->cascadeOnDelete();
            $table->string('step_key');
            $table->unsignedInteger('sequence');
            $table->string('plan_digest');
            $table->json('intent');
            $table->uuid('receipt_id')->unique();
            $table->string('status')->default('intended');
            $table->json('receipt')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamps();
            $table->unique(['instance_app_projection_id', 'step_key']);
            $table->unique(['instance_app_projection_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instance_app_projection_steps');
        Schema::dropIfExists('instance_app_projections');
        Schema::dropIfExists('instance_app_updates');
    }
};
