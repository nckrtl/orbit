<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_document_cleanups', function (Blueprint $table): void {
            $table->string('claim_token', 64)->nullable();
            $table->timestamp('claim_expires_at')->nullable();
            $table->index(['next_attempt_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('project_document_cleanups', function (Blueprint $table): void {
            $table->dropIndex(['next_attempt_at', 'id']);
            $table->dropColumn(['claim_token', 'claim_expires_at']);
        });
    }
};
