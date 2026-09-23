<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** ADR 0123: a group created with a planner keeps that fact for its Todo commit and reviewer handoff. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_groups', static function (Blueprint $table): void {
            $table->boolean('plan')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('task_groups', static function (Blueprint $table): void {
            $table->dropColumn('plan');
        });
    }
};
