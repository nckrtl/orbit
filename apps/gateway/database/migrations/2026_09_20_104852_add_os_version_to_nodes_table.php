<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', static function (Blueprint $table): void {
            $table->string('os_version')->nullable()->after('architecture');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', static function (Blueprint $table): void {
            $table->dropColumn('os_version');
        });
    }
};
