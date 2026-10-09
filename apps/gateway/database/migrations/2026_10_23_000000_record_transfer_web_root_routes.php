<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A transfer records the Routes with a web root that it moved at cutover, so source cleanup retires
 * their old leaves and firewall rules even when a Route is removed before cleanup finishes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_transfers', static function (Blueprint $table): void {
            $table->json('web_root_route_ids')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('instance_transfers')->whereNotNull('web_root_route_ids')->exists()) {
            throw new RuntimeException('Cannot discard retained Instance transfer web-root Route evidence.');
        }

        Schema::table('instance_transfers', static function (Blueprint $table): void {
            $table->dropColumn('web_root_route_ids');
        });
    }
};
