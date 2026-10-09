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
        Schema::create('sandbox_images', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider');
            $table->string('zone');
            $table->string('status');
            $table->string('step');
            $table->timestamp('step_started_at');
            $table->string('failed_step')->nullable();
            $table->string('script_sha256', 64);
            $table->string('credential_fingerprint', 64);
            $table->timestamp('build_create_attempted_at')->nullable();
            $table->uuid('build_server_id')->nullable();
            $table->uuid('build_disk_id')->nullable();
            $table->string('build_address')->nullable();
            $table->json('build_host_key')->nullable();
            $table->timestamp('clean_attempted_at')->nullable();
            $table->timestamp('templatize_attempted_at')->nullable();
            $table->uuid('template_id')->nullable()->unique();
            $table->timestamp('smoke_create_attempted_at')->nullable();
            $table->uuid('smoke_server_id')->nullable();
            $table->uuid('smoke_disk_id')->nullable();
            $table->string('smoke_address')->nullable();
            $table->json('smoke_host_key')->nullable();
            $table->json('caches')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_detail')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
            $table->index(['provider', 'status']);
        });
    }

    public function down(): void
    {
        if (DB::table('sandbox_images')->whereIn('status', ['building', 'failing', 'published'])->exists()) {
            throw new RuntimeException('Finish or retire outstanding sandbox images before rolling back their ownership table.');
        }
        Schema::dropIfExists('sandbox_images');
    }
};
