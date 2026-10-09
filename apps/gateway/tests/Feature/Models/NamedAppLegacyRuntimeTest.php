<?php

declare(strict_types=1);

use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Nodes\RoleName;
use App\Domain\SourceControl\ApplicationDirectory;
use App\Infrastructure\Instances\ProductionApplicationPaths;
use App\Infrastructure\Instances\ProductionPhpRuntimeConfigRenderer;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Project;

it('keeps each converted root\'s runtime directory, document root and production link', function (string $root, ?string $override, string $directory, string $documentRoot, string $link, string $target): void {
    $project = Project::query()->create([
        'name' => 'Runtime apps', 'slug' => 'runtime-apps',
        'repository_url' => 'https://example.test/runtime-apps.git', 'apps' => fixture_apps($root),
    ]);
    $node = Node::query()->create(['name' => 'runtime-node', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => 'node.example.test']);
    $node->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/home/site/releases/initial', 'production_home' => '/home/site', 'production_user' => 'site', 'app_overrides' => fixture_app_overrides($override), 'source_is_laravel' => true]);
    // The values the removed root produced before conversion.
    $effective = $override ?? $root;
    $program = 'link=__ENVIRONMENT_PATH__; target=__ENVIRONMENT_TARGET__';
    $beforeLink = ProductionApplicationPaths::render($program, $effective);
    $beforeDirectory = ApplicationDirectory::resolve('/home/site/current', $effective);

    $after = $instance->fresh();
    expect($after->applicationDirectory())->toBe('/home/site/current'.$directory)->toBe($beforeDirectory);
    expect($after->effectiveRoot())->toBe('/home/site/current/'.$documentRoot);
    expect(new ProductionPhpRuntimeConfigRenderer()->render(ProductionPhpRuntimeIdentity::forProvisioning($after, '8.5'), false)->pool)
        ->toContain('chdir = /home/site/current'.$directory);
    expect(ProductionApplicationPaths::render($program, $after->sourceRoot(), $after->applicationPath()))->toBe($beforeLink)->toContain($link, $target);
    $after->setRelation('node', (new Node)->setRelation('roles', collect([new NodeRole(['role' => RoleName::AppDev])])));
    expect($after->applicationDirectory())->toBe('/home/site/releases/initial'.$directory);
    expect($after->applicationDirectory().'/.env')->toBe('/home/site/releases/initial'.$directory.'/.env');
    expect($after->relativeWebRoot())->toBe($documentRoot);
})->with([
    'public' => ['public', null, '', 'public', 'link=.env', '../../.env'],
    'nested public' => ['apps/site/public', null, '/apps/site', 'apps/site/public', "application_suffix='/apps/site'", '../../../../.env'],
    'nested nonpublic' => ['apps/site/web', null, '/apps/site/web', 'apps/site/web', 'link=.env', '../../.env'],
    'single nonpublic' => ['web', null, '/web', 'web', 'link=.env', '../../.env'],
    'override nonpublic' => ['public', 'apps/site/web', '/apps/site/web', 'apps/site/web', 'link=.env', '../../.env'],
    'override public' => ['apps/other/web', 'apps/site/public', '/apps/site', 'apps/site/public', "application_suffix='/apps/site'", '../../../../.env'],
    'equal override' => ['web', 'web', '/web', 'web', 'link=.env', '../../.env'],
]);
