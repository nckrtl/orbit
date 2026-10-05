<?php

declare(strict_types=1);

use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\ApplicationDirectory;
use App\Infrastructure\Instances\ProductionApplicationPaths;
use App\Infrastructure\Instances\ProductionPhpRuntimeConfigRenderer;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Project;
use Illuminate\Support\Facades\Schema;

function named_app_runtime_project(array $attributes = []): Project
{
    return Project::query()->create([
        'name' => 'Runtime apps', 'slug' => 'runtime-apps',
        'repository_url' => 'https://example.test/runtime-apps.git', 'root' => 'public',
        ...$attributes,
    ]);
}

it('preserves persisted named app runtime directories document roots and production links across migration', function (string $root, ?string $override, string $directory, string $documentRoot, string $link, string $target): void {
    $project = named_app_runtime_project(['root' => $root]);
    $node = Node::query()->create(['name' => 'runtime-node', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => 'node.example.test']);
    $node->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/home/site/releases/initial', 'production_home' => '/home/site', 'production_user' => 'site', 'root' => $override, 'source_is_laravel' => true]);
    $effective = $override ?? $root;
    $program = 'link=__ENVIRONMENT_PATH__; target=__ENVIRONMENT_TARGET__';
    $beforeLink = ProductionApplicationPaths::render($program, $effective);
    $beforeDirectory = ApplicationDirectory::resolve('/home/site/current', $effective);
    $migration = require database_path('migrations/2026_10_12_000000_add_named_apps_to_projects.php');
    Schema::table('projects', fn ($table) => $table->dropColumn('apps'));
    Schema::table('instances', fn ($table) => $table->dropColumn('app_overrides'));
    $legacy = Instance::query()->findOrFail($instance->id);
    expect($legacy->applicationDirectory())->toBe($beforeDirectory);
    expect($legacy->effectiveRoot())->toBe('/home/site/current/'.$effective);
    $renderer = new ProductionPhpRuntimeConfigRenderer;
    $beforePool = $renderer->render(ProductionPhpRuntimeIdentity::forProvisioning($legacy, '8.5'), false)->pool;

    try {
        $migration->up();
        $after = $instance->fresh();
        expect($after->applicationDirectory())->toBe('/home/site/current'.$directory)->toBe($beforeDirectory);
        expect($after->effectiveRoot())->toBe('/home/site/current/'.$documentRoot);
        expect($renderer->render(ProductionPhpRuntimeIdentity::forProvisioning($after, '8.5'), false)->pool)->toBe($beforePool)->toContain('chdir = /home/site/current'.$directory);
        expect(ProductionApplicationPaths::render($program, $after->sourceRoot(), $after->applicationPath()))->toBe($beforeLink)->toContain($link, $target);
        $after->setRelation('node', (new Node)->setRelation('roles', collect([new NodeRole(['role' => RoleName::AppDev])])));
        expect($after->applicationDirectory())->toBe('/home/site/releases/initial'.$directory);
        expect($after->applicationDirectory().'/.env')->toBe('/home/site/releases/initial'.$directory.'/.env');
        expect($after->relativeWebRoot())->toBe($documentRoot);
    } finally {
        $migration->up();
    }
})->with([
    'public' => ['public', null, '', 'public', 'link=.env', '../../.env'],
    'nested public' => ['apps/site/public', null, '/apps/site', 'apps/site/public', "application_suffix='/apps/site'", '../../../../.env'],
    'nested nonpublic' => ['apps/site/web', null, '/apps/site/web', 'apps/site/web', 'link=.env', '../../.env'],
    'single nonpublic' => ['web', null, '/web', 'web', 'link=.env', '../../.env'],
    'override nonpublic' => ['public', 'apps/site/web', '/apps/site/web', 'apps/site/web', 'link=.env', '../../.env'],
    'override public' => ['apps/other/web', 'apps/site/public', '/apps/site', 'apps/site/public', "application_suffix='/apps/site'", '../../../../.env'],
    'equal override' => ['web', 'web', '/web', 'web', 'link=.env', '../../.env'],
]);

it('refuses legacy Project writes that would erase named app names or siblings', function (array $apps, array $changes): void {
    $project = named_app_runtime_project(['apps' => $apps]);
    $before = $project->fresh()->getAttributes();

    expect(fn () => $project->update($changes))->toThrow(ResourceOperationException::class);
    expect($project->fresh()->getAttributes())->toBe($before);
})->with([
    'sibling' => [[['name' => 'web', 'path' => '.', 'web_root' => 'public', 'type' => 'laravel-app'], ['name' => 'docs', 'path' => 'docs', 'web_root' => null, 'type' => 'monorepo']], ['root' => 'apps/site/public']],
    'retained name' => [[['name' => 'site', 'path' => '.', 'web_root' => 'public', 'type' => 'laravel-app']], ['type' => 'monorepo']],
]);

it('refuses legacy Instance writes that would erase named app override data', function (): void {
    $project = named_app_runtime_project(['apps' => [['name' => 'web', 'path' => '.', 'web_root' => 'public', 'type' => 'laravel-app'], ['name' => 'docs', 'path' => 'docs', 'web_root' => null, 'type' => 'monorepo']]]);
    $node = Node::query()->create(['name' => 'bridge-node', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => 'node.example.test']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/srv/repo', 'app_overrides' => ['docs' => ['path' => 'preview/docs', 'web_root' => null]]]);
    $before = $instance->fresh()->getAttributes();

    expect(fn () => $instance->update(['root' => 'apps/site/public']))->toThrow(ResourceOperationException::class);
    expect($instance->fresh()->getAttributes())->toBe($before);
});

it('validates legacy named app bridge writes before changing persisted state', function (string $owner): void {
    $project = named_app_runtime_project();
    $node = Node::query()->create(['name' => 'validation-node', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => 'node.example.test']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/srv/repo']);
    $model = $owner === 'project' ? $project : $instance;
    $before = $model->fresh()->getAttributes();

    expect(fn () => $model->update(['root' => '.']))->toThrow(ResourceOperationException::class);
    expect($model->fresh()->getAttributes())->toBe($before);
})->with(['project', 'instance']);
