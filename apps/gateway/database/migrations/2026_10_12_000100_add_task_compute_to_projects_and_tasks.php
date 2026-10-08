<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('task_compute')->default('shared');
        });
        Schema::table('tasks', function (Blueprint $table): void {
            $table->string('task_compute')->nullable();
            $table->text('capacity_wait_reason')->nullable();
        });
        DB::table('tasks')->whereNull('parent_id')->whereNotIn('status', ['backlog', 'todo'])->update(['task_compute' => 'shared']);
        DB::table('tasks')->whereNull('parent_id')->whereNotNull('taskable_id')->update(['task_compute' => 'shared']);
    }

    public function down(): void
    {
        Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn(['task_compute', 'capacity_wait_reason']));
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('task_compute'));
    }
};
