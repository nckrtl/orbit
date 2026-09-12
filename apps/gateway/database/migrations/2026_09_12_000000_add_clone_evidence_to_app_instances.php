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
            $table->unsignedBigInteger('clone_candidate_id')->nullable()->after('migration_required')->index();
            $table->string('clone_candidate_commit', 64)->nullable()->after('clone_candidate_id');
            $table->string('clone_requested_branch')->nullable()->after('clone_candidate_commit');
            $table->string('clone_preview_name', 253)->nullable()->after('clone_requested_branch');
            $table->string('clone_preview_hostname', 253)->nullable()->after('clone_preview_name');
            $table->text('clone_sqlite_source_path')->nullable()->after('clone_preview_hostname');
            $table->timestamp('clone_completed_at')->nullable()->after('clone_sqlite_source_path');
        });
    }

    public function down(): void
    {
        if (DB::table('app_instances')
            ->whereNotNull('clone_candidate_id')
            ->orWhereNotNull('clone_candidate_commit')
            ->orWhereNotNull('clone_requested_branch')
            ->orWhereNotNull('clone_preview_name')
            ->orWhereNotNull('clone_preview_hostname')
            ->orWhereNotNull('clone_sqlite_source_path')
            ->orWhereNotNull('clone_completed_at')
            ->exists()) {
            throw new RuntimeException('Cannot discard retained AppInstance clone evidence.');
        }

        Schema::table('app_instances', static function (Blueprint $table): void {
            $table->dropIndex(['clone_candidate_id']);
            $table->dropColumn([
                'clone_candidate_id',
                'clone_candidate_commit',
                'clone_requested_branch',
                'clone_preview_name',
                'clone_preview_hostname',
                'clone_sqlite_source_path',
                'clone_completed_at',
            ]);
        });
    }
};
