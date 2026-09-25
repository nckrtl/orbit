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
        Schema::table('apps', static function (Blueprint $table): void {
            $table->text('task_check')->nullable();
        });

        // ADR 0125: every existing Project keeps the `composer check` gate it had; type defaults apply to new Projects only.
        DB::table('apps')->update(['task_check' => 'composer check']);
    }

    public function down(): void
    {
        Schema::table('apps', static function (Blueprint $table): void {
            $table->dropColumn('task_check');
        });
    }
};
