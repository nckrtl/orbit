<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_releases', static function (Blueprint $table): void {
            $table->id();
            $table->string('release_id', 12)->index();
            $table->char('sha', 40);
            $table->string('trigger', 16);
            $table->string('outcome', 32)->index();
            $table->boolean('migrations_ran')->default(false);
            $table->boolean('retryable')->default(false);
            $table->boolean('cleanup_paused')->default(false);
            $table->string('snapshot_path')->nullable();
            $table->string('previous_release_id', 12)->nullable();
            $table->json('phases');
            $table->string('error_code')->nullable();
            $table->text('message')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_releases');
    }
};
