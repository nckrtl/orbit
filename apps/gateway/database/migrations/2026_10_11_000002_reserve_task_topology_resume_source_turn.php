<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The resource reply reserves its original source turn before any send, including a null legacy turn id. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_comments', static function (Blueprint $table): void {
            $table->json('topology_resume')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('task_comments', static function (Blueprint $table): void {
            $table->dropColumn('topology_resume');
        });
    }
};
