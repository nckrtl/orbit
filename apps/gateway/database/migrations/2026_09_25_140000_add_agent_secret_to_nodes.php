<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stores the SHA-256 hash of each Node agent's secret (ADR 0155). Every existing Node runs an agent
 * that sends no secret, so it starts exempt until a converge installs one that does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', static function (Blueprint $table): void {
            $table->string('agent_secret_hash', 64)->nullable();
            $table->boolean('agent_secret_exempt')->default(false);
        });

        DB::table('nodes')->update(['agent_secret_exempt' => true]);
    }

    public function down(): void
    {
        Schema::table('nodes', static function (Blueprint $table): void {
            $table->dropColumn(['agent_secret_hash', 'agent_secret_exempt']);
        });
    }
};
