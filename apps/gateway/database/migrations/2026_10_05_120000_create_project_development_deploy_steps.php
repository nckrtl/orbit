<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_development_deploy_steps', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name', 63);
            $table->text('command');
            $table->unsignedInteger('timeout_seconds');
            $table->boolean('required')->default(true);
            $table->unsignedInteger('position');
            $table->timestamps();
            $table->unique(['project_id', 'name']);
            $table->unique(['project_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_development_deploy_steps');
    }
};
