<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('app_instances', static function (Blueprint $table): void {
            $table->string('source_identity', 64)->nullable()->after('starting_commit');
        });
    }

    public function down(): void
    {
        Schema::table('app_instances', static function (Blueprint $table): void {
            $table->dropColumn('source_identity');
        });
    }
};
