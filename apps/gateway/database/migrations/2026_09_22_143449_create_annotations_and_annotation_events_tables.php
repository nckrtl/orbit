<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('annotations', function (Blueprint $table): void {
            $table->string('id', 128)->primary();
            $table->foreignId('app_instance_id')->nullable()->constrained('app_instances')->nullOnDelete();
            $table->string('thread_id', 128)->nullable();
            $table->string('status')->default('pending');
            $table->string('delivery')->default('queued');
            $table->json('context');
            $table->text('error')->nullable();
            $table->text('summary')->nullable();
            $table->unsignedBigInteger('revision')->default(0);
            $table->unsignedBigInteger('submission_order')->default(0);
            $table->uuid('command_id');
            $table->uuid('message_id');
            $table->json('command')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamps();
            $table->index(['app_instance_id', 'status', 'created_at']);
        });
        Schema::create('annotation_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_instance_id')->nullable()->constrained('app_instances')->nullOnDelete();
            $table->json('payload');
            $table->timestamps();
            $table->index(['app_instance_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('annotation_events');
        Schema::dropIfExists('annotations');
    }
};
