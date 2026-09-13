<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('herdr_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('node_id')->constrained();
            $table->string('session');
            $table->string('user');
            $table->foreignId('process_id')->nullable()->constrained('processes');
            $table->unsignedSmallInteger('observer_port');
            $table->string('observer_hostname');
            $table->string('observer_url')->nullable();
            $table->string('observer_status')->default('pending');
            $table->string('observer_error')->nullable();
            $table->string('status')->default('provisioning');
            $table->string('herdr_version')->nullable();
            $table->unsignedInteger('protocol')->nullable();
            $table->boolean('handoff_supported')->default(false);
            $table->boolean('publish_observer')->default(false);
            $table->string('failed_step')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamps();
            $table->unique(['node_id', 'session']);
        });

        Schema::create('jwks_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('kid')->unique();
            $table->string('algorithm');
            $table->text('private_pem');
            $table->text('public_pem');
            $table->timestamps();
        });

        Schema::create('herdr_observation_nonces', function (Blueprint $table): void {
            $table->string('jti')->primary();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('herdr_observation_nonces');
        Schema::dropIfExists('jwks_keys');
        Schema::dropIfExists('herdr_sessions');
    }
};
