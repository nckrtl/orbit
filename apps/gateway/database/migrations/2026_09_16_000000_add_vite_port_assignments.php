<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_instances', function (Blueprint $table): void {
            $table->unsignedInteger('vite_port')->nullable();
            $table->unique(['node_id', 'vite_port']);
        });
        Schema::create('vite_port_assignments', function (Blueprint $table): void {
            $table->foreignId('app_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('port');
            $table->primary(['app_instance_id', 'node_id']);
            $table->unique(['node_id', 'port']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vite_port_assignments');
        Schema::table('app_instances', function (Blueprint $table): void {
            $table->dropUnique(['node_id', 'vite_port']);
            $table->dropColumn('vite_port');
        });
    }
};
