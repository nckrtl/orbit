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
            $table->string('workspace_creation')->nullable();
            $table->string('workspace_copy_mode')->nullable();
            $table->string('workspace_fallback_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->dropColumn([
                'workspace_creation',
                'workspace_copy_mode',
                'workspace_fallback_reason',
            ]);
        });
    }
};
