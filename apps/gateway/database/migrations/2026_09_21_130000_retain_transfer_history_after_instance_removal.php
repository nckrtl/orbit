<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_instance_transfers', static function (Blueprint $table): void {
            $table->dropForeign(['app_instance_id']);
            $table->foreignId('app_instance_id')->nullable()->change();
            $table->foreign('app_instance_id')->references('id')->on('app_instances')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('app_instance_transfers', static function (Blueprint $table): void {
            $table->dropForeign(['app_instance_id']);
            $table->foreignId('app_instance_id')->nullable(false)->change();
            $table->foreign('app_instance_id')->references('id')->on('app_instances')->restrictOnDelete();
        });
    }
};
