<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('app_instances', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_id')->constrained()->restrictOnDelete();
            $table->foreignId('node_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('environment')->default('development');
            $table->string('source_kind')->default('managed_clone');
            $table->text('checkout_path');
            $table->string('root')->nullable();
            $table->string('branch')->nullable();
            $table->string('starting_commit', 64)->nullable();
            $table
                ->enum('status', ['reserved', 'checkout_prepared', 'source_resolved', 'active'])
                ->default('reserved')
                ->index();
            $table->timestamps();

            $table->unique(['app_id', 'name']);
            $table->unique(['node_id', 'checkout_path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_instances');
    }
};
