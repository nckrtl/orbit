<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instances', function (Blueprint $table): void {
            $table->unsignedInteger('ssr_port')->nullable();
            $table->unique(['node_id', 'ssr_port']);
        });
        Schema::create('ssr_port_assignments', function (Blueprint $table): void {
            $table->foreignId('instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('port');
            $table->primary(['instance_id', 'node_id']);
            $table->unique(['node_id', 'port']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ssr_port_assignments');
        Schema::table('instances', function (Blueprint $table): void {
            $table->dropUnique(['node_id', 'ssr_port']);
            $table->dropColumn('ssr_port');
        });
    }
};
