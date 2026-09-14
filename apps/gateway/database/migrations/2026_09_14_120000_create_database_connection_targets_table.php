<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_connection_targets', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('database_connection_id')->constrained()->restrictOnDelete();
            $table->foreignId('app_instance_id')->constrained()->cascadeOnDelete();
            $table->string('prefix', 32);
            $table->timestamps();
            $table->unique(['app_instance_id', 'prefix']);
        });
    }
};
