<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_threads', static function (Blueprint $table): void {
            $table->unsignedInteger('archive_attempts')->default(0);
            $table->timestamp('archive_retry_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('agent_threads', static function (Blueprint $table): void {
            $table->dropIndex('agent_threads_archive_retry_at_index');
            $table->dropColumn(['archive_attempts', 'archive_retry_at']);
        });
    }
};
