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
        Schema::table('task_sandboxes', function (Blueprint $table): void {
            $table->timestamp('review_started_at')->nullable();
            $table->timestamp('parked_at')->nullable();
            $table->boolean('preview')->default(false);
        });
    }

    public function down(): void
    {
        if (DB::table('task_sandboxes')->whereNotNull('review_started_at')->where('state', '!=', 'destroyed')->exists()) {
            throw new RuntimeException('End sandbox review waits before rolling back their retention deadlines.');
        }
        Schema::table('task_sandboxes', function (Blueprint $table): void {
            $table->dropColumn(['review_started_at', 'parked_at', 'preview']);
        });
    }
};
