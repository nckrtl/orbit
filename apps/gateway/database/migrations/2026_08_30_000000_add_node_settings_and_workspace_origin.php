<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('nodes', static function (Blueprint $table): void {
            $table->json('settings')->nullable();
        });

        Schema::table('workspaces', static function (Blueprint $table): void {
            $table->string('checkout_path_origin')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nodes', static function (Blueprint $table): void {
            $table->dropColumn('settings');
        });

        Schema::table('workspaces', static function (Blueprint $table): void {
            $table->dropColumn('checkout_path_origin');
        });
    }
};
