<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0164: the identity of a Gateway fixup, such as `conflict:main` or `check:Gateway`.
 * Null on every operator subtask, which the cap does not count.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->text('fixup_problem')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->dropColumn('fixup_problem');
        });
    }
};
