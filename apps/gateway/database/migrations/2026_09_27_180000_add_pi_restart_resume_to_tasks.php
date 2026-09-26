<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0167: one subtask may reserve two resumes of a Pi turn the server restarted.
 * The key is stored before the send and reused only while that same interruption is unresolved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->unsignedInteger('pi_restart_resumes')->default(0);
            $table->string('pi_restart_key')->nullable();
            $table->foreignId('pi_restart_thread_id')->nullable()->constrained('agent_threads')->nullOnDelete();
            $table->string('pi_restart_source_turn_id')->nullable();
            $table->string('pi_restart_reservation')->nullable();
            $table->string('pi_restart_session_revision')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pi_restart_thread_id');
            $table->dropColumn([
                'pi_restart_resumes',
                'pi_restart_key',
                'pi_restart_source_turn_id',
                'pi_restart_reservation',
                'pi_restart_session_revision',
            ]);
        });
    }
};
