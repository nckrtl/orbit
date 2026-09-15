<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dependency_packages', function (Blueprint $table): void {
            $table->id();
            $table->enum('ecosystem', ['composer', 'npm']);
            $table->string('name');
            $table->timestamps();
            $table->unique(['ecosystem', 'name']);
            $table->unique(['id', 'ecosystem']);
        });

        Schema::create('app_instance_dependency_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_instance_id')->constrained()->cascadeOnDelete();
            $table->enum('ecosystem', ['composer', 'npm']);
            $table->boolean('present');
            $table->timestamp('observed_at');
            $table->text('project_root');
            $table->text('source_reference')->nullable();
            $table->json('file_hashes');
            $table->string('format')->nullable();
            $table->timestamps();
            $table->unique(['app_instance_id', 'ecosystem'], 'dependency_observation_instance_ecosystem');
            $table->unique(['id', 'ecosystem'], 'dependency_observation_id_ecosystem');
        });

        Schema::create('app_instance_dependency_resolutions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('observation_id');
            $table->unsignedBigInteger('dependency_package_id');
            $table->enum('ecosystem', ['composer', 'npm']);
            $table->text('locator');
            $table->text('version');
            $table->boolean('regular');
            $table->boolean('development');
            $table->text('source_reference')->nullable();
            $table->text('integrity')->nullable();
            $table->timestamps();
            $table->unique(['observation_id', 'locator'], 'dependency_resolution_locator');
            $table->unique(['observation_id', 'id'], 'dependency_resolution_observation_id');
            $table->foreign(['observation_id', 'ecosystem'], 'dependency_resolution_observation')
                ->references(['id', 'ecosystem'])->on('app_instance_dependency_observations')->cascadeOnDelete();
            $table->foreign(['dependency_package_id', 'ecosystem'], 'dependency_resolution_package')
                ->references(['id', 'ecosystem'])->on('dependency_packages')->restrictOnDelete();
            $table->index(['dependency_package_id', 'ecosystem'], 'dependency_resolution_package_lookup');
        });

        Schema::create('app_instance_dependency_edges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('observation_id')->constrained('app_instance_dependency_observations')->cascadeOnDelete();
            $table->unsignedBigInteger('from_resolution_id')->nullable();
            $table->unsignedBigInteger('to_resolution_id')->nullable();
            $table->string('name');
            $table->text('constraint');
            $table->enum('kind', ['dependency', 'peer']);
            $table->enum('scope', ['regular', 'development']);
            $table->boolean('optional');
            $table->timestamps();
            $table->foreign(['observation_id', 'from_resolution_id'], 'dependency_edge_from')
                ->references(['observation_id', 'id'])->on('app_instance_dependency_resolutions')->cascadeOnDelete();
            $table->foreign(['observation_id', 'to_resolution_id'], 'dependency_edge_to')
                ->references(['observation_id', 'id'])->on('app_instance_dependency_resolutions')->cascadeOnDelete();
            $table->index(['observation_id', 'from_resolution_id'], 'dependency_edge_from_lookup');
            $table->index(['observation_id', 'to_resolution_id'], 'dependency_edge_to_lookup');
        });

        // SQLite treats nulls as distinct in ordinary unique indexes. Include nullness
        // separately so root and unresolved edges also have one semantic identity.
        DB::statement('CREATE UNIQUE INDEX dependency_edge_identity ON app_instance_dependency_edges (
            observation_id, (from_resolution_id IS NULL), COALESCE(from_resolution_id, 0),
            (to_resolution_id IS NULL), COALESCE(to_resolution_id, 0), name, "constraint", kind, scope, optional
        )');

        Schema::create('app_instance_dependency_scan_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_instance_id')->constrained()->cascadeOnDelete();
            $table->enum('ecosystem', ['composer', 'npm']);
            $table->timestamp('attempted_at');
            $table->string('error_code')->nullable();
            $table->timestamps();
            $table->index(['app_instance_id', 'ecosystem', 'id'], 'dependency_attempt_latest');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_instance_dependency_scan_attempts');
        Schema::dropIfExists('app_instance_dependency_edges');
        Schema::dropIfExists('app_instance_dependency_resolutions');
        Schema::dropIfExists('app_instance_dependency_observations');
        Schema::dropIfExists('dependency_packages');
    }
};
