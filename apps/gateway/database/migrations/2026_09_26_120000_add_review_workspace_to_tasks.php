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
            $table->string('review_workspace_head', 64)->nullable()->after('review_notified_turn_id');
            $table->string('review_workspace_tree', 64)->nullable()->after('review_workspace_head');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->dropColumn(['review_workspace_head', 'review_workspace_tree']);
        });
    }
};
