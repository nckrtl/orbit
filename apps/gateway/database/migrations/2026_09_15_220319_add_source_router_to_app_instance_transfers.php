<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('app_instance_transfers', function (Blueprint $table) {
            $table->unsignedBigInteger('source_router_node_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('app_instance_transfers')->whereNotNull('source_router_node_id')->exists()) {
            throw new RuntimeException('Cannot discard retained AppInstance transfer Router evidence.');
        }

        Schema::table('app_instance_transfers', function (Blueprint $table) {
            $table->dropColumn('source_router_node_id');
        });
    }
};
