<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('apps', static function (Blueprint $table): void {
            $table->string('main_branch')->nullable();
            $table->string('root')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('apps', static function (Blueprint $table): void {
            $table->dropColumn(['main_branch', 'root']);
        });
    }
};
