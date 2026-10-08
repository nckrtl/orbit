<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('task_sandboxes', function (Blueprint $table): void {
            $table->text('pi_token')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('task_sandboxes')->whereNotNull('pi_token')->where('state', '!=', 'destroyed')->exists()) {
            throw new RuntimeException('Destroy credentialed sandboxes before rolling back Pi credentials.');
        }
        Schema::table('task_sandboxes', function (Blueprint $table): void {
            $table->dropColumn('pi_token');
        });
    }
};
