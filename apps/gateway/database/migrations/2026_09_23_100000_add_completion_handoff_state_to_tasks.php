<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->unsignedInteger('completion_attempt')->default(1);
            $table->foreignId('completion_handoff_comment_id')->nullable()->constrained('task_comments')->nullOnDelete();
            $table->unsignedInteger('completion_reminder_attempt')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->dropConstrainedForeignId('completion_handoff_comment_id');
            $table->dropColumn(['completion_attempt', 'completion_reminder_attempt']);
        });
    }
};
