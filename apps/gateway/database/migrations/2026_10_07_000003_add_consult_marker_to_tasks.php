<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A consult row is the question an implementer's blocked receipt opens.
 * consult_comment_id marks the blocked receipt whose consult is still in flight.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('task_questions') && ! Schema::hasColumn('task_questions', 'consult')) {
            Schema::table('task_questions', static function (Blueprint $table): void {
                $table->boolean('consult')->default(false);
            });
        }

        if (Schema::hasTable('tasks') && Schema::hasColumn('tasks', 'parent_id') && ! Schema::hasColumn('tasks', 'consult_comment_id')) {
            Schema::table('tasks', static function (Blueprint $table): void {
                $table->unsignedBigInteger('consult_comment_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('task_questions') && Schema::hasColumn('task_questions', 'consult')) {
            Schema::table('task_questions', static function (Blueprint $table): void {
                $table->dropColumn('consult');
            });
        }

        if (Schema::hasTable('tasks') && Schema::hasColumn('tasks', 'consult_comment_id')) {
            Schema::table('tasks', static function (Blueprint $table): void {
                $table->dropColumn('consult_comment_id');
            });
        }
    }
};
