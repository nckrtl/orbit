<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0164: the identity of a Gateway fixup, such as `conflict:main` or `check:Gateway`.
 * Null on every operator subtask, which the cap does not count. `fixup_head_sha` is the pull request
 * head the fixup was created for; no further fixup is appended while the head is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->text('fixup_problem')->nullable();
            $table->string('fixup_head_sha')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->dropColumn(['fixup_problem', 'fixup_head_sha']);
        });
    }
};
