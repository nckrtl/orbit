<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_instance_deployments', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_instance_id')->constrained()->cascadeOnDelete();
            $table->string('release', 128)->nullable();
            $table->string('branch', 255)->nullable();
            $table->string('commit', 64)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('status', 16);
            $table->string('failed_step', 32)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('selected_release', 128)->nullable();
            $table->string('triggered_by', 255)->nullable();
            $table->json('events')->nullable();
            $table->timestamps();

            $table->index(['app_instance_id', 'started_at']);
        });
    }
};
