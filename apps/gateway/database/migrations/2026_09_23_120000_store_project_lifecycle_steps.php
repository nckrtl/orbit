<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_lifecycle_steps', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->string('phase', 16);
            $table->string('name', 63);
            $table->text('command');
            $table->unsignedInteger('timeout_seconds');
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->unique(['app_id', 'phase', 'name']);
            $table->unique(['app_id', 'phase', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_lifecycle_steps');
    }
};
