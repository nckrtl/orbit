<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_comments', static function (Blueprint $table): void {
            $table->string('receipt_hash', 64)->nullable();
            $table->unique(['task_id', 'receipt_hash']);
            $table->json('pull_request')->nullable();
            $table->dropColumn(['reviewer_thread_id', 'driver_turn', 'pr_url']);
        });
    }

    public function down(): void
    {
        Schema::table('task_comments', static function (Blueprint $table): void {
            $table->dropUnique(['task_id', 'receipt_hash']);
            $table->dropColumn(['receipt_hash', 'pull_request']);
            $table->string('reviewer_thread_id')->nullable();
            $table->string('driver_turn')->nullable();
            $table->text('pr_url')->nullable();
        });
    }
};
