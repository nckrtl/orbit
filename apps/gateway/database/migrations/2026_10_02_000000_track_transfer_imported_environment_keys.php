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
            $table->json('imported_environment_keys')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('app_instance_transfers', static function (Blueprint $table): void {
            $table->dropColumn('imported_environment_keys');
        });
    }
};
