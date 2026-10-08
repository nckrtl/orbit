<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_document_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('project_document_entries')->restrictOnDelete();
            $table->string('kind', 6);
            $table->string('name', 255)->collation('BINARY');
            $table->unsignedBigInteger('sibling_scope');
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'sibling_scope', 'name']);
        });
        Schema::create('project_document_uploads', function (Blueprint $table): void {
            $table->id();
            // Recovery survives Project and entry removal; these are identities, not cascading foreign keys.
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('entry_id')->nullable();
            $table->string('storage_key')->unique();
            $table->string('state')->default('active');
            $table->timestamps();
            $table->index(['state', 'created_at']);
        });
        Schema::create('project_document_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entry_id')->constrained('project_document_entries')->cascadeOnDelete();
            $table->foreignId('upload_id')->unique()->constrained('project_document_uploads')->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->string('media_type', 127);
            $table->unsignedInteger('size_bytes');
            $table->string('sha256', 64);
            $table->string('storage_key')->unique();
            $table->foreignId('created_by_node_id')->nullable()->constrained('nodes')->nullOnDelete();
            $table->timestamp('created_at');
            $table->unique(['entry_id', 'number']);
        });
        Schema::create('project_document_cleanups', function (Blueprint $table): void {
            $table->id();
            $table->string('storage_key')->unique();
            $table->boolean('retained_fence')->default(false);
            $table->boolean('pending')->default(true);
            $table->timestamp('next_attempt_at');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error_code')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_document_cleanups');
        Schema::dropIfExists('project_document_versions');
        Schema::dropIfExists('project_document_uploads');
        Schema::dropIfExists('project_document_entries');
    }
};
