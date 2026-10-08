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
            $table->text('model_key')->nullable();
            $table->string('model_proxy_origin')->nullable();
            $table->timestamp('model_key_registered_at')->nullable();
            $table->timestamp('model_key_revoked_at')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('task_sandboxes')->whereNotNull('model_key')->exists()) {
            throw new RuntimeException('Revoke sandbox model keys before rolling back their storage.');
        }
        Schema::table('task_sandboxes', function (Blueprint $table): void {
            $table->dropColumn(['model_key', 'model_proxy_origin', 'model_key_registered_at', 'model_key_revoked_at']);
        });
    }
};
