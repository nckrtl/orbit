<?php

declare(strict_types=1);

use App\Domain\Projects\ProjectApps;
use App\Domain\Projects\ProjectType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('projects', 'apps')) {
            Schema::table('projects', static function (Blueprint $table): void {
                $table->json('apps')->nullable();
            });
        }
        if (! Schema::hasColumn('instances', 'app_overrides')) {
            Schema::table('instances', static function (Blueprint $table): void {
                $table->json('app_overrides')->nullable();
            });
        }

        DB::transaction(static function (): void {
            foreach (DB::table('projects')->whereNull('apps')->orderBy('id')->get(['id', 'root', 'type']) as $project) {
                if (! is_string($project->type) || ($project->root !== null && ! is_string($project->root))) {
                    throw new RuntimeException('Cannot convert ambiguous Project app configuration.');
                }
                $apps = ProjectApps::validate(ProjectApps::legacy($project->root, ProjectType::from($project->type)));
                DB::table('projects')->where('id', $project->id)->update(['apps' => json_encode($apps, JSON_THROW_ON_ERROR)]);
            }
            foreach (DB::table('instances')->whereNull('app_overrides')->orderBy('id')->get(['id', 'project_id', 'root']) as $instance) {
                if ($instance->root !== null && ! is_string($instance->root)) {
                    throw new RuntimeException('Cannot convert ambiguous Instance app configuration.');
                }
                $overrides = $instance->root === null ? [] : ['web' => ProjectApps::fromRoot($instance->root)];
                $projectApps = DB::table('projects')->where('id', $instance->project_id)->value('apps');
                if (! is_string($projectApps)) {
                    throw new RuntimeException('Cannot convert an Instance without its Project apps.');
                }
                ProjectApps::effective(ProjectApps::validate(json_decode($projectApps, true, flags: JSON_THROW_ON_ERROR)), $overrides);
                DB::table('instances')->where('id', $instance->id)->update(['app_overrides' => json_encode($overrides === [] ? new stdClass : $overrides, JSON_THROW_ON_ERROR)]);
            }
        });
    }

    public function down(): void
    {
        foreach (DB::table('projects')->get(['apps', 'root', 'type']) as $project) {
            if (! is_string($project->type) || ! is_string($project->apps) || ($project->root !== null && ! is_string($project->root)) || json_decode($project->apps, true, flags: JSON_THROW_ON_ERROR) !== ProjectApps::legacy($project->root, ProjectType::from($project->type))) {
                throw new RuntimeException('Cannot discard named apps that differ from the retained Project root.');
            }
        }
        foreach (DB::table('instances')->get(['app_overrides', 'root']) as $instance) {
            $expected = $instance->root === null ? [] : ProjectApps::fromRoot((string) $instance->root);
            $expected = $instance->root === null ? [] : ['web' => $expected];
            if (! is_string($instance->app_overrides) || json_decode($instance->app_overrides, true, flags: JSON_THROW_ON_ERROR) !== $expected) {
                throw new RuntimeException('Cannot discard named app overrides that differ from the retained Instance root.');
            }
        }
        Schema::table('instances', static fn (Blueprint $table) => $table->dropColumn('app_overrides'));
        Schema::table('projects', static fn (Blueprint $table) => $table->dropColumn('apps'));
    }
};
