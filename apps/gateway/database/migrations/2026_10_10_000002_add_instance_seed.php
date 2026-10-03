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
            $table->text('seed_path')->nullable();
            $table->string('seed_commit', 64)->nullable();
            $table->text('seed_repository')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('instances', function (Blueprint $table): void {
            $table->dropColumn(['seed_path', 'seed_commit', 'seed_repository']);
        });
    }
};
