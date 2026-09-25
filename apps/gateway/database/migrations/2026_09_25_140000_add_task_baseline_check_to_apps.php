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
            $table->text('task_baseline_check')->nullable();
        });

        DB::table('apps')->update(['task_baseline_check' => 'composer check']);
    }

    public function down(): void
    {
        Schema::table('apps', static function (Blueprint $table): void {
            $table->dropColumn('task_baseline_check');
        });
    }
};
