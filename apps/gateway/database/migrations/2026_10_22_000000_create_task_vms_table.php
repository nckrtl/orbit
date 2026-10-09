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
        Schema::create('task_vms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_id')->constrained('tasks');
            $table->foreignId('host_node_id')->constrained('nodes');
            $table->foreignId('node_id')->nullable()->unique()->constrained('nodes')->nullOnDelete();
            $table->string('provider');
            $table->string('name')->unique();
            $table->string('state');
            $table->string('address')->nullable();
            $table->string('wireguard_ip');
            $table->text('pi_token');
            $table->text('model_key')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('destroyed_at')->nullable();
            $table->timestamps();
            $table->index(['host_node_id', 'state']);
            $table->index('state');
        });

        DB::statement("CREATE UNIQUE INDEX task_vms_live_group_id_unique ON task_vms (group_id) WHERE state != 'destroyed'");
        DB::statement("CREATE UNIQUE INDEX task_vms_live_wireguard_ip_unique ON task_vms (wireguard_ip) WHERE state != 'destroyed'");
    }

    public function down(): void
    {
        if (DB::table('task_vms')->where('state', '!=', 'destroyed')->exists()) {
            throw new RuntimeException('Destroy outstanding task VMs before rolling back their table.');
        }
        Schema::dropIfExists('task_vms');
    }
};
