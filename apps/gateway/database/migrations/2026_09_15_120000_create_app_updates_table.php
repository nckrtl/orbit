<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_updates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->string('fingerprint');
            $table->string('requested_slug')->nullable();
            $table->string('requested_repository_url')->nullable();
            $table->string('requested_default_branch')->nullable();
            $table->string('requested_root')->nullable();
            $table->string('previous_slug');
            $table->string('previous_repository_url');
            $table->string('previous_default_branch')->nullable();
            $table->string('previous_root')->nullable();
            $table->json('inventory')->nullable();
            $table->json('evidence')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamps();

            $table->index(['app_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_updates');
    }
};
