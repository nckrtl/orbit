<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The T3 Code layer: the profiles Nodes share settings through, the T3 servers that registered an
 * admin session, and the pairing links the Gateway minted for each Node.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('t3_profiles', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->json('settings');
            // Increases on every settings replace, so a client can detect a change and refuse a lost update.
            $table->unsignedInteger('settings_version')->default(1);
            $table->timestamps();
        });

        // A Node belongs to at most one profile.
        Schema::create('t3_profile_bindings', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('node_id')->unique()->constrained('nodes')->cascadeOnDelete();
            $table->foreignId('t3_profile_id')->constrained('t3_profiles')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('t3_environments', static function (Blueprint $table): void {
            $table->id();
            $table->string('environment_id')->unique();
            $table->string('label');
            $table->string('url');
            $table->foreignId('node_id')->nullable()->constrained('nodes')->nullOnDelete();
            $table->string('server_version')->nullable();
            $table->text('admin_session');
            $table->timestamp('admin_session_expires_at');
            $table->timestamp('registered_at');
            $table->timestamps();
        });

        Schema::create('t3_pairings', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('node_id')->constrained('nodes')->cascadeOnDelete();
            $table->foreignId('t3_environment_id')->constrained('t3_environments')->cascadeOnDelete();
            // The pairing link id on the T3 server, and the client label its session carries there.
            $table->string('pairing_link_id');
            $table->string('client_label');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['t3_environment_id', 'node_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t3_pairings');
        Schema::dropIfExists('t3_environments');
        Schema::dropIfExists('t3_profile_bindings');
        Schema::dropIfExists('t3_profiles');
    }
};
