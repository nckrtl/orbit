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
            $table->unsignedInteger('completion_handoff_attempt')->nullable();
            $table->string('completion_handoff_turn_id')->nullable();
            $table->string('completion_handoff_check_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->dropColumn(['completion_handoff_attempt', 'completion_handoff_turn_id', 'completion_handoff_check_id']);
        });
    }
};
