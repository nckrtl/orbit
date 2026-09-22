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
            $table->unsignedInteger('review_notified_attempt')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->dropColumn('review_notified_attempt');
        });
    }
};
