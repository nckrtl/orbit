<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0133: a subtask's typed deliverables, the confirmations a run receipt gives, and the evidence
 * the handoff check records. Each is read and written as a whole, so each is one JSON column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->json('deliverables')->nullable();
        });
        Schema::table('task_comments', static function (Blueprint $table): void {
            $table->json('deliverables')->nullable();
        });
        Schema::table('task_checks', static function (Blueprint $table): void {
            $table->json('deliverable_evidence')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('task_checks', static function (Blueprint $table): void {
            $table->dropColumn('deliverable_evidence');
        });
        Schema::table('task_comments', static function (Blueprint $table): void {
            $table->dropColumn('deliverables');
        });
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->dropColumn('deliverables');
        });
    }
};
