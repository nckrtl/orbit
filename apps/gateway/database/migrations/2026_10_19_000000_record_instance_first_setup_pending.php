<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marks an Instance whose create has not finished its first setup. Only `instance:create` sets it,
 * and a completed setup clears it. A create retry resumes the database and setup only while it is
 * set, so a retry never treats a live Instance whose later `instance:setup` failed as unfinished.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instances', function (Blueprint $table): void {
            $table->boolean('first_setup_pending')->default(false);
        });
        // A create that stopped before activation still owns its first setup. An active Instance
        // keeps the default: Orbit cannot tell an unfinished create from a later failed setup.
        DB::table('instances')
            ->whereIn('status', ['reserved', 'checkout_prepared', 'source_resolved'])
            ->update(['first_setup_pending' => true]);
    }

    public function down(): void
    {
        Schema::table('instances', function (Blueprint $table): void {
            $table->dropColumn('first_setup_pending');
        });
    }
};
