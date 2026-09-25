<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The scheduler records when it reserved a group, so the tick can return a group stranded in reserved to todo. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_groups', static function (Blueprint $table): void {
            $table->timestamp('reserved_at')->nullable();
        });

        DB::table('task_groups')->where('status', 'reserved')->update(['reserved_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('task_groups', static function (Blueprint $table): void {
            $table->dropColumn('reserved_at');
        });
    }
};
