<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records the `.env` keys Orbit may remove from an Instance's workload file: the keys its last
 * synchronization wrote and the keys a detach removed since. A synchronization refuses to drop any
 * other key, so a file that `env:import` never read keeps its keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instances', function (Blueprint $table): void {
            $table->json('environment_owned_keys')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('instances', function (Blueprint $table): void {
            $table->dropColumn('environment_owned_keys');
        });
    }
};
