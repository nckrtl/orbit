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
            $table->unsignedInteger('agentation_port')->nullable();
            $table->unique(['node_id', 'agentation_port']);
        });
    }

    public function down(): void
    {
        Schema::table('app_instances', function (Blueprint $table): void {
            $table->dropUnique(['node_id', 'agentation_port']);
            $table->dropColumn('agentation_port');
        });
    }
};
