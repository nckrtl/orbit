<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function named_app_project(array $attributes = []): Project
{
    return Project::query()->create([...[
        'name' => 'Named apps', 'slug' => 'named-apps',
        'repository_url' => 'https://example.test/named-apps.git', 'root' => 'public',
    ], ...$attributes]);
}

it('stores named apps sorted by name and keeps identity within the Project', function (): void {
    $apps = [
        ['name' => 'site', 'path' => 'apps/site', 'web_root' => 'public', 'type' => 'laravel-app'],
        ['name' => 'default', 'path' => 'packages/client', 'web_root' => null, 'type' => 'node-package'],
    ];
    $project = named_app_project(['apps' => $apps]);

    expect($project->fresh()->apps)->toBe([$apps[1], $apps[0]]);
    $other = named_app_project(['slug' => 'other', 'repository_url' => 'https://example.test/other.git', 'apps' => $apps]);
    expect($other->fresh()->apps)->toBe([$apps[1], $apps[0]]);
});

it('refuses a duplicate named app name or path without changing stored configuration', function (array $second, string $code): void {
    $first = ['name' => 'web', 'path' => '.', 'web_root' => 'public', 'type' => 'laravel-app'];
    $project = named_app_project(['apps' => [$first]]);

    try {
        $project->update(['apps' => [$first, $second]]);
        test()->fail('Duplicate app configuration was accepted.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe($code);
        expect($exception->status)->toBe(422);
    }
    expect($project->fresh()->apps)->toBe([$first]);
})->with([
    'name' => [['name' => 'web', 'path' => 'apps/site', 'web_root' => 'public', 'type' => 'monorepo'], 'project.app_name_conflict'],
    'path' => [['name' => 'site', 'path' => '.', 'web_root' => 'assets', 'type' => 'monorepo'], 'project.app_path_conflict'],
]);

it('refuses invalid named app configuration', function (array $changes): void {
    $app = ['name' => 'web', 'path' => '.', 'web_root' => 'public', 'type' => 'laravel-app'];

    expect(fn () => named_app_project(['apps' => [[...$app, ...$changes]]]))->toThrow(ResourceOperationException::class);
    expect(Project::query()->count())->toBe(0);
})->with([
    'uppercase' => [['name' => 'Web']], 'leading hyphen' => [['name' => '-web']],
    'long name' => [['name' => str_repeat('a', 64)]], 'empty name' => [['name' => '']],
    'absolute' => [['path' => '/apps']], 'traversal' => [['path' => 'apps/../site']],
    'dot segment' => [['path' => 'apps/./site']], 'empty segment' => [['path' => 'apps//site']],
    'trailing slash' => [['path' => 'apps/']], 'backslash' => [['path' => 'apps\\site']],
    'drive prefix' => [['path' => 'C:apps']], 'non ASCII' => [['path' => 'café']],
    'NUL' => [['path' => "apps\0site"]], 'long path' => [['path' => str_repeat('a', 256)]],
    'long composed path' => [['path' => str_repeat('a', 250), 'web_root' => 'public']],
    'dot web root' => [['web_root' => '.']],
    'unknown type' => [['type' => 'php']], 'unknown member' => [['extra' => true]],
]);

it('requires a nonempty named app list with all four fields', function (array $apps): void {
    expect(fn () => named_app_project(['apps' => $apps]))->toThrow(ResourceOperationException::class);
})->with([
    'empty' => [[]],
    'missing web root' => [[['name' => 'web', 'path' => '.', 'type' => 'laravel-app']]],
    'object not list' => [['web' => ['name' => 'web', 'path' => '.', 'web_root' => 'public', 'type' => 'laravel-app']]],
]);

it('migrates each legacy root and explicit override independently to a named app without losing other state', function (string $root, string $type, string $path, ?string $webRoot, ?string $override, array $expectedOverride): void {
    $project = named_app_project(['root' => $root, 'type' => $type]);
    $node = Node::query()->create(['name' => 'named-app-node', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => 'node.example.test']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/srv/repo', 'root' => $override, 'status' => 'active']);
    $migration = require database_path('migrations/2026_10_19_000000_add_named_apps_to_projects.php');
    // The predecessor schema cannot retain guards installed by its dependent runtime migration.
    $runtimeGuards = DB::table('sqlite_master')->where('type', 'trigger')->get(['name', 'sql'])->filter(static fn ($trigger): bool => str_contains($trigger->sql, 'projects.apps') || str_contains($trigger->sql, 'SELECT apps FROM projects') || str_contains($trigger->sql, 'app_overrides'));
    foreach ($runtimeGuards as $trigger) {
        DB::statement('DROP TRIGGER "'.str_replace('"', '""', $trigger->name).'"');
    }
    Schema::table('projects', fn ($table) => $table->dropColumn('apps'));
    Schema::table('instances', fn ($table) => $table->dropColumn('app_overrides'));
    $beforeProject = (array) DB::table('projects')->find($project->id);
    $beforeInstance = (array) DB::table('instances')->find($instance->id);

    try {
        $migration->up();
        $migration->up();
        expect($project->fresh()->apps)->toBe([['name' => 'web', 'path' => $path, 'web_root' => $webRoot, 'type' => $type]]);
        expect($instance->fresh()->app_overrides)->toBe($expectedOverride);
        $afterProject = (array) DB::table('projects')->find($project->id);
        $afterInstance = (array) DB::table('instances')->find($instance->id);
        unset($afterProject['apps'], $afterInstance['app_overrides']);
        expect($afterProject)->toBe($beforeProject);
        expect($afterInstance)->toBe($beforeInstance);
    } finally {
        $migration->up();
        foreach ($runtimeGuards as $trigger) {
            DB::statement($trigger->sql);
        }
    }
})->with([
    'public inherits' => ['public', 'laravel-app', '.', 'public', null, []],
    'nested public' => ['apps/site/public', 'laravel-app', 'apps/site', 'public', null, []],
    'other final segment' => ['apps/site/web', 'monorepo', 'apps/site/web', null, null, []],
    'single segment' => ['web', 'monorepo', 'web', null, null, []],
    'nested override' => ['public', 'laravel-app', '.', 'public', 'apps/site/public', ['web' => ['path' => 'apps/site', 'web_root' => 'public']]],
    'equal explicit override' => ['public', 'laravel-app', '.', 'public', 'public', ['web' => ['path' => '.', 'web_root' => 'public']]],
    'Laravel package' => ['.', 'laravel-package', '.', null, '.', ['web' => ['path' => '.', 'web_root' => null]]],
    'Node package serving override' => ['.', 'node-package', '.', null, 'apps/demo/web', ['web' => ['path' => 'apps/demo/web', 'web_root' => null]]],
]);

it('resolves the named app path rather than inferring it from its web root in development and production', function (RoleName $role, string $base): void {
    $project = named_app_project(['apps' => [['name' => 'site', 'path' => 'apps/site', 'web_root' => 'dist/assets', 'type' => 'monorepo']]]);
    $node = new Node;
    $node->setRelation('roles', collect([new NodeRole(['role' => $role])]));
    $instance = new Instance(['checkout_path' => '/srv/repo', 'production_home' => '/home/site', 'app_overrides' => ['site' => ['path' => 'apps/preview', 'web_root' => 'web']]]);
    $instance->setRelation('project', $project);
    $instance->setRelation('node', $node);

    expect($instance->applicationDirectory('site'))->toBe($base.'/apps/preview');
    expect($instance->effectiveApps())->toBe([['name' => 'site', 'path' => 'apps/preview', 'web_root' => 'web', 'type' => 'monorepo']]);
})->with([
    'development' => [RoleName::AppDev, '/srv/repo'],
    'production' => [RoleName::AppProd, '/home/site/current'],
]);

it('refuses an invalid named app override before saving it', function (mixed $overrides, string $code): void {
    $project = named_app_project(['apps' => [
        ['name' => 'site', 'path' => 'apps/site', 'web_root' => 'public', 'type' => 'laravel-app'],
        ['name' => 'client', 'path' => 'packages/client', 'web_root' => null, 'type' => 'node-package'],
    ]]);
    $node = Node::query()->create(['name' => 'override-node', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => 'node.example.test']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/srv/repo']);

    try {
        $instance->update(['app_overrides' => $overrides]);
        test()->fail('Invalid override was accepted.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe($code);
        expect($exception->status)->toBe(422);
    }
    expect($instance->fresh()->app_overrides)->toBe([]);
})->with([
    'unknown app' => [['web' => ['path' => '.', 'web_root' => 'public']], 'instance.app_overrides_invalid'],
    'missing member' => [['site' => ['path' => '.']], 'instance.app_overrides_invalid'],
    'extra member' => [['site' => ['path' => '.', 'web_root' => 'public', 'type' => 'monorepo']], 'instance.app_overrides_invalid'],
    'wrong type' => [['site' => ['path' => 1, 'web_root' => 'public']], 'instance.app_overrides_invalid'],
    'traversal' => [['site' => ['path' => '../site', 'web_root' => 'public']], 'instance.app_overrides_invalid'],
    'duplicate effective path' => [['site' => ['path' => 'packages/client', 'web_root' => 'public']], 'project.app_path_conflict'],
    'null map' => [null, 'instance.app_overrides_invalid'],
]);

it('requires a named app selector on several apps and refuses unknown names', function (): void {
    $project = named_app_project(['apps' => [
        ['name' => 'site', 'path' => 'apps/site', 'web_root' => 'public', 'type' => 'laravel-app'],
        ['name' => 'client', 'path' => 'packages/client', 'web_root' => null, 'type' => 'node-package'],
    ]]);
    $instance = new Instance;
    $instance->setRelation('project', $project);

    foreach ([null => 'app.required', 'absent' => 'app.not_found'] as $selector => $code) {
        try {
            $instance->applicationPath($selector === '' ? null : $selector);
            test()->fail('An ambiguous or unknown selector was accepted.');
        } catch (ResourceOperationException $exception) {
            expect($exception->errorCode)->toBe($code);
        }
    }
    expect($instance->applicationPath('client'))->toBe('packages/client');
});

it('refuses rollback that would discard changed named app configuration', function (): void {
    named_app_project(['apps' => [['name' => 'site', 'path' => 'apps/site', 'web_root' => 'public', 'type' => 'laravel-app']]]);
    $migration = require database_path('migrations/2026_10_19_000000_add_named_apps_to_projects.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Cannot discard named apps');
    expect(Schema::hasColumn('projects', 'apps'))->toBeTrue();
    expect(Schema::hasColumn('instances', 'app_overrides'))->toBeTrue();
});

it('refuses rollback that would discard an explicit named app override', function (): void {
    $project = named_app_project();
    $node = Node::query()->create(['name' => 'rollback-node', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => 'node.example.test']);
    Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/srv/repo', 'app_overrides' => ['web' => ['path' => 'apps/preview', 'web_root' => 'public']]]);
    $migration = require database_path('migrations/2026_10_19_000000_add_named_apps_to_projects.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Cannot discard named app overrides');
    expect(Schema::hasColumn('projects', 'apps'))->toBeTrue();
    expect(Schema::hasColumn('instances', 'app_overrides'))->toBeTrue();
});
