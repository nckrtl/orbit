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
        Schema::create('app_instance_transfers', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('app_instance_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('source_node_id');
            $table->unsignedBigInteger('destination_node_id');
            $table->string('requested_name')->nullable();
            $table->string('destination_name');
            $table->text('destination_path');
            $table->string('destination_domain', 253);
            $table->text('sqlite_source_path')->nullable();
            $table->string('source_layout');
            $table->text('source_path');
            $table->text('common_repository_path')->nullable();
            $table->unsignedBigInteger('source_route_id');
            $table->unsignedBigInteger('destination_route_id')->nullable();
            $table->string('status');
            $table->string('current_step');
            $table->string('failed_step')->nullable();
            $table->string('error_code')->nullable();
            $table->json('recovery_evidence')->nullable();
            $table->timestamp('cutover_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['app_instance_id', 'status']);
        });
    }

    public function down(): void
    {
        if (DB::table('app_instance_transfers')->exists()) {
            throw new RuntimeException('Cannot discard retained AppInstance transfer evidence.');
        }

        Schema::dropIfExists('app_instance_transfers');
    }
};
