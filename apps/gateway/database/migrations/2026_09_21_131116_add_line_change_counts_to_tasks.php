<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['tasks', 'task_groups'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->unsignedBigInteger('lines_added')->nullable();
                $table->unsignedBigInteger('lines_deleted')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['tasks', 'task_groups'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn(['lines_added', 'lines_deleted']);
            });
        }
    }
};
