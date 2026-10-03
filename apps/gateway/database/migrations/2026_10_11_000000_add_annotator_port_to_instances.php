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
            $table->unsignedInteger('annotator_port')->nullable();
            $table->unique(['node_id', 'annotator_port']);
        });
    }

    public function down(): void
    {
        Schema::table('instances', function (Blueprint $table): void {
            $table->dropUnique(['node_id', 'annotator_port']);
            $table->dropColumn('annotator_port');
        });
    }
};
