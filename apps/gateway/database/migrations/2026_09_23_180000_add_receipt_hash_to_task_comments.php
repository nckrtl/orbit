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
        });
    }

    public function down(): void
    {
        Schema::table('task_comments', static function (Blueprint $table): void {
            $table->dropUnique(['task_id', 'receipt_hash']);
            $table->dropColumn('receipt_hash');
        });
    }
};
