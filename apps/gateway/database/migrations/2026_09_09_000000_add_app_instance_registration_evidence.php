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
        Schema::table('app_instances', static function (Blueprint $table): void {
            $table->text('registration_original_path')->nullable()->after('migration_required');
            $table->uuid('registration_request_id')->nullable()->after('registration_original_path')->index();
            $table->boolean('registration_primary')->default(false)->after('registration_request_id');
            $table->boolean('registration_include_worktrees')->default(false)->after('registration_primary');
            $table->text('registration_repository_url')->nullable()->after('registration_include_worktrees');
            $table->string('registration_repository_identity')->nullable()->after('registration_repository_url');
            $table->string('registration_source_digest', 64)->nullable()->after('registration_repository_identity');
            $table->boolean('registration_detached')->default(false)->after('registration_source_digest');
            $table->string('registration_default_branch')->nullable()->after('registration_detached');
            $table->string('registration_inferred_slug')->nullable()->after('registration_default_branch');
            $table->string('registration_inferred_root')->nullable()->after('registration_inferred_slug');
            $table->text('registration_common_repository_path')->nullable()->after('registration_inferred_root');
            $table->json('registration_worktree_paths')->nullable()->after('registration_common_repository_path');
            $table->string('registration_relocation_state')->nullable()->after('registration_worktree_paths');
            $table->text('registration_authoritative_path')->nullable()->after('registration_relocation_state');
            $table->string('registration_route_hostname', 253)->nullable()->after('registration_authoritative_path');
            $table->string('registration_route_provenance')->nullable()->after('registration_route_hostname');
            $table->timestamp('registration_completed_at')->nullable()->after('registration_route_provenance');
            $table->unique(['node_id', 'registration_original_path']);
        });
    }

    public function down(): void
    {
        $unfinished = DB::table('app_instances')
            ->whereNotNull('registration_request_id')
            ->whereNull('registration_completed_at')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        if ($unfinished !== []) {
            throw new RuntimeException(
                'Cannot roll back while AppInstance registrations are incomplete: '.implode(', ', $unfinished),
            );
        }

        Schema::table('app_instances', static function (Blueprint $table): void {
            $table->dropUnique(['node_id', 'registration_original_path']);
            $table->dropIndex(['registration_request_id']);
            $table->dropColumn([
                'registration_original_path',
                'registration_request_id',
                'registration_primary',
                'registration_include_worktrees',
                'registration_repository_url',
                'registration_repository_identity',
                'registration_source_digest',
                'registration_detached',
                'registration_default_branch',
                'registration_inferred_slug',
                'registration_inferred_root',
                'registration_common_repository_path',
                'registration_worktree_paths',
                'registration_relocation_state',
                'registration_authoritative_path',
                'registration_route_hostname',
                'registration_route_provenance',
                'registration_completed_at',
            ]);
        });
    }
};
