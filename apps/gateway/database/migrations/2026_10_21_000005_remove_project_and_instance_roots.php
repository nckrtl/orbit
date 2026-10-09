<?php

declare(strict_types=1);

use App\Domain\Projects\ProjectApps;
use App\Domain\Projects\ProjectType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Named apps replace the Project and Instance `root` fields, with no alias. */
return new class extends Migration
{
    public function up(): void
    {
        // Rows written after the named-app conversion through the removed root fields convert the same way.
        $hasType = Schema::hasColumn('projects', 'type');
        foreach (DB::table('projects')->whereNull('apps')->get() as $project) {
            $type = $hasType && is_string($project->type ?? null) ? ProjectType::from($project->type) : ProjectType::LaravelApp;
            $root = is_string($project->root ?? null) ? $project->root : null;
            DB::table('projects')->where('id', $project->id)->update(['apps' => json_encode(ProjectApps::validate(ProjectApps::legacy($root, $type)), JSON_THROW_ON_ERROR)]);
        }
        foreach (DB::table('instances')->whereNull('app_overrides')->whereNotNull('root')->get(['id', 'root']) as $instance) {
            $overrides = ['web' => ProjectApps::fromRoot((string) $instance->root)];
            DB::table('instances')->where('id', $instance->id)->update(['app_overrides' => json_encode($overrides, JSON_THROW_ON_ERROR)]);
        }
        if (DB::table('project_updates')->whereNotIn('status', ['complete', 'rolled_back'])->whereNotNull('requested_root')->exists()) {
            throw new RuntimeException('Finish or roll back the incomplete Project root update first.');
        }

        $this->replaceRemovalRoot(self::LEGACY_ROOT, self::appRoot());
        Schema::table('projects', static fn (Blueprint $table) => $table->dropColumn('root'));
        Schema::table('instances', static fn (Blueprint $table) => $table->dropColumn('root'));
        Schema::table('project_updates', static fn (Blueprint $table) => $table->dropColumn(['requested_root', 'previous_root']));
    }

    public function down(): void
    {
        $projectRoots = [];
        foreach (DB::table('projects')->whereNotNull('apps')->get(['id', 'apps']) as $project) {
            $apps = ProjectApps::validate(json_decode(is_string($project->apps) ? $project->apps : '', true, flags: JSON_THROW_ON_ERROR));
            if (count($apps) !== 1 || $apps[0]['name'] !== 'web') {
                throw new RuntimeException('Only a Project with the single app [web] has a root.');
            }
            $projectRoots[$project->id] = self::root($apps[0]);
        }
        $instanceRoots = [];
        foreach (DB::table('instances')->whereNotNull('app_overrides')->get(['id', 'app_overrides']) as $instance) {
            $overrides = json_decode(is_string($instance->app_overrides) ? $instance->app_overrides : '', true, flags: JSON_THROW_ON_ERROR);
            $web = is_array($overrides) ? ($overrides['web'] ?? null) : null;
            $instanceRoots[$instance->id] = is_array($web) && is_string($web['path'] ?? null) && (is_string($web['web_root'] ?? null) || ($web['web_root'] ?? null) === null)
                ? self::root(['path' => $web['path'], 'web_root' => $web['web_root'] ?? null])
                : null;
        }

        Schema::table('projects', static fn (Blueprint $table) => $table->string('root')->nullable());
        Schema::table('instances', static fn (Blueprint $table) => $table->string('root')->nullable());
        Schema::table('project_updates', static function (Blueprint $table): void {
            $table->string('requested_root')->nullable();
            $table->string('previous_root')->nullable();
        });
        foreach ($projectRoots as $id => $root) {
            DB::table('projects')->where('id', $id)->update(['root' => $root]);
        }
        foreach ($instanceRoots as $id => $root) {
            DB::table('instances')->where('id', $id)->update(['root' => $root]);
        }
        $this->replaceRemovalRoot(self::appRoot(), self::LEGACY_ROOT);
    }

    private const string LEGACY_ROOT = 'COALESCE(instances.root, projects.root)';

    /** The production removal guard compares the sole app's repository-relative serving root. */
    private static function appRoot(): string
    {
        $name = "json_extract(projects.apps, '$[0].name')";
        $path = "COALESCE(json_extract(instances.app_overrides, '$.' || {$name} || '.path'), json_extract(projects.apps, '$[0].path'))";
        $webRoot = "CASE WHEN json_type(instances.app_overrides, '$.' || {$name}) IS NOT NULL THEN json_extract(instances.app_overrides, '$.' || {$name} || '.web_root') ELSE json_extract(projects.apps, '$[0].web_root') END";

        return "(CASE WHEN ({$webRoot}) IS NULL THEN {$path} WHEN ({$path}) = '.' THEN ({$webRoot}) ELSE ({$path}) || '/' || ({$webRoot}) END)";
    }

    private function replaceRemovalRoot(string $from, string $to): void
    {
        $sql = DB::table('sqlite_master')->where('type', 'trigger')->where('name', 'instance_removal_members_insert')->value('sql');
        // A schema without the removal guard, or one that already compares the target expression, needs no change.
        if (! is_string($sql) || (substr_count($sql, $from) === 0 && substr_count($sql, $to) === 1)) {
            return;
        }
        if (substr_count($sql, $from) !== 1) {
            throw new RuntimeException('The instance_removal_members_insert trigger is incompatible.');
        }
        DB::statement('DROP TRIGGER instance_removal_members_insert');
        DB::statement(str_replace($from, $to, $sql));
    }

    /** @param array{path: string, web_root: ?string} $app */
    private static function root(array $app): string
    {
        return match (true) {
            $app['web_root'] === null => $app['path'],
            $app['path'] === '.' => $app['web_root'],
            default => "{$app['path']}/{$app['web_root']}",
        };
    }
};
