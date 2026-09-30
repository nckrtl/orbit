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

        // Projects that already existed kept the composer check they ran. New Projects store no command unless one is sent.
        DB::table('apps')->update(['task_check' => 'composer check']);
    }

    public function down(): void
    {
        Schema::table('apps', static function (Blueprint $table): void {
            $table->dropColumn('task_check');
        });
    }
};
