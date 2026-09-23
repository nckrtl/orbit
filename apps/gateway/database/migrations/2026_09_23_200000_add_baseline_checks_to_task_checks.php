<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_checks', static function (Blueprint $table): void {
            $table->string('kind')->default('handoff');
            $table->string('failed_step')->nullable();
            $table->foreignId('task_comment_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('task_checks', static function (Blueprint $table): void {
            $table->dropColumn(['kind', 'failed_step']);
        });
    }
};
