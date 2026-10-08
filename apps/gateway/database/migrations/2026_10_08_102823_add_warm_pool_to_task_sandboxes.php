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
        Schema::table('task_sandboxes', fn (Blueprint $table) => $table->boolean('warm_pool')->default(false)->index());
    }

    public function down(): void
    {
        if (DB::table('task_sandboxes')->where('warm_pool', true)->where('state', '!=', 'destroyed')->exists()) {
            throw new RuntimeException('Drain outstanding warm sandboxes before removing their ownership marker.');
        }
        Schema::table('task_sandboxes', function (Blueprint $table): void {
            $table->dropIndex(['warm_pool']);
            $table->dropColumn('warm_pool');
        });
    }
};
