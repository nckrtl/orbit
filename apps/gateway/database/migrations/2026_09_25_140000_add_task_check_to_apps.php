<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', static function (Blueprint $table): void {
            $table->text('task_check')->nullable();
        });

        // ADR 0125: Laravel Projects keep the `composer check` gate; monorepo and node-package Projects run no check command.
        DB::table('apps')
            ->whereIn('type', ['laravel-app', 'laravel-package'])
            ->update(['task_check' => 'composer check']);
    }

    public function down(): void
    {
        Schema::table('apps', static function (Blueprint $table): void {
            $table->dropColumn('task_check');
        });
    }
};
