<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('process_definitions', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('app_id')->constrained()->cascadeOnDelete();
            $table->string('name', 63);
            $table->json('environments');
            $table->json('spec');
            $table->timestamps();

            $table->unique(['app_id', 'name']);
        });

        Schema::create('schedule_definitions', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('app_id')->constrained()->cascadeOnDelete();
            $table->string('name', 63);
            $table->json('environments');
            $table->json('spec');
            $table->timestamps();

            $table->unique(['app_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_definitions');
        Schema::dropIfExists('process_definitions');
    }
};
