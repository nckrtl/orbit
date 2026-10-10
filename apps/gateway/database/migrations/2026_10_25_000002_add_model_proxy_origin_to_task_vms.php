<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A task VM keeps the CLIProxyAPI origin of its model key, so a changed setting never strands the
 * key. The plain group index serves lookups of destroyed rows, which the partial unique index skips.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_vms', function (Blueprint $table): void {
            $table->string('model_proxy_origin')->nullable()->after('model_key');
            $table->index('group_id');
        });
    }

    public function down(): void
    {
        Schema::table('task_vms', function (Blueprint $table): void {
            $table->dropIndex(['group_id']);
            $table->dropColumn('model_proxy_origin');
        });
    }
};
