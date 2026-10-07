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
        Schema::create('task_sandboxes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('group_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->foreignId('node_id')->nullable()->constrained('nodes')->nullOnDelete();
            $table->string('provider');
            $table->string('name')->unique();
            $table->string('state');
            $table->string('desired_power');
            $table->string('network_policy')->default('bootstrap');
            $table->json('spec');
            $table->string('credential_fingerprint', 64)->nullable();
            $table->uuid('server_id')->nullable();
            $table->uuid('disk_id')->nullable();
            $table->string('public_address')->nullable();
            $table->timestamp('create_attempted_at')->nullable();
            $table->timestamp('firewall_configured_at')->nullable();
            $table->timestamp('destroyed_at')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamps();
            $table->index(['provider', 'state']);
            $table->index(['group_id', 'state']);
        });
    }

    public function down(): void
    {
        if (DB::table('task_sandboxes')->where('state', '!=', 'destroyed')->exists()) {
            throw new RuntimeException('Destroy outstanding task sandboxes before rolling back their ownership table.');
        }
        Schema::dropIfExists('task_sandboxes');
    }
};
