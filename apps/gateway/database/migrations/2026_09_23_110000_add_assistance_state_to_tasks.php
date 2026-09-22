<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_groups', static function (Blueprint $table): void {
            $table->boolean('assistance_requested')->default(false);
            $table->text('assistance_reason')->nullable();
        });
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->boolean('assistance_requested')->default(false);
            $table->text('assistance_reason')->nullable();
            $table->unsignedInteger('communication_failures')->default(0);
            $table->foreignId('resolution_delivered_comment_id')->nullable()->constrained('task_comments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->dropConstrainedForeignId('resolution_delivered_comment_id');
            $table->dropColumn(['assistance_requested', 'assistance_reason', 'communication_failures']);
        });
        Schema::table('task_groups', static function (Blueprint $table): void {
            $table->dropColumn(['assistance_requested', 'assistance_reason']);
        });
    }
};
