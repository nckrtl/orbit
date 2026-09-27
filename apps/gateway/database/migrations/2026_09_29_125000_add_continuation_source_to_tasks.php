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
            $table->foreignId('continuation_of_task_id')
                ->nullable()
                ->constrained('tasks')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->dropConstrainedForeignId('continuation_of_task_id');
        });
    }
};
