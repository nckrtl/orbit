<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Projects\ProjectType;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Project;
use Tests\TestCase;

uses(TestCase::class);

it('resolves dependency trees in the effective app directory', function (ProjectType $type, ?bool $laravel, ?string $override, string $expected): void {
    $project = new Project(['type' => $type, 'apps' => fixture_apps('apps/site/public', $type)]);
    $node = new Node;
    $node->setRelation('roles', collect([new NodeRole(['role' => RoleName::AppDev])]));
    $instance = new Instance(['checkout_path' => '/srv/repository', 'app_overrides' => fixture_app_overrides($override), 'source_is_laravel' => $laravel]);
    $instance->setRelation('project', $project);
    $instance->setRelation('node', $node);

    expect($instance->dependencyDirectory())->toBe($expected);
})->with([
    'Laravel inherited' => [ProjectType::LaravelApp, null, null, '/srv/repository/apps/site'],
    'Laravel override public' => [ProjectType::LaravelApp, true, 'public', '/srv/repository'],
    'Laravel monorepo' => [ProjectType::Monorepo, true, null, '/srv/repository/apps/site'],
    'unclassified monorepo' => [ProjectType::Monorepo, null, null, '/srv/repository/apps/site'],
    'package' => [ProjectType::LaravelPackage, false, null, '/srv/repository/apps/site'],
    'Symfony inherited' => [ProjectType::SymfonyApp, false, null, '/srv/repository/apps/site'],
]);

it('requires a Route and serves PHP for web-serving project types only', function (ProjectType $type, bool $serving): void {
    $instance = new Instance(['checkout_path' => '/srv/repository', 'source_is_laravel' => false]);
    // A package with a web root is a serving app; only path `.` with no web root is non-serving.
    $instance->setRelation('project', new Project(['type' => $type, 'apps' => fixture_apps($serving ? 'public' : '.', $type)]));

    expect($instance->requiresRoute())->toBe($serving)
        ->and($instance->servesPhp())->toBe($serving);
})->with([
    'Laravel app' => [ProjectType::LaravelApp, true],
    'Symfony app' => [ProjectType::SymfonyApp, true],
    'Laravel package' => [ProjectType::LaravelPackage, false],
    'Node package' => [ProjectType::NodePackage, false],
]);

it('resolves the application directory from the inherited web root', function (?string $root, string $suffix): void {
    $project = new Project(['apps' => fixture_apps($root, $root === '.' ? ProjectType::LaravelPackage : ProjectType::LaravelApp)]);
    $node = new Node;
    $node->setRelation('roles', collect([new NodeRole(['role' => RoleName::AppDev])]));
    $instance = new Instance(['checkout_path' => '/srv/checkouts/site', 'production_home' => '/srv/production/site']);
    $instance->setRelation('project', $project);
    $instance->setRelation('node', $node);

    expect($instance->applicationDirectory())->toBe('/srv/checkouts/site'.$suffix);
})->with([
    'root public' => ['public', ''],
    'nested public' => ['server/web/public', '/server/web'],
    'earlier public segment' => ['public/server/web/public', '/public/server/web'],
    'no public suffix' => ['server/web', '/server/web'],
    'not a public segment' => ['server/web/notpublic', '/server/web/notpublic'],
    'public is not the final segment' => ['server/public/assets', '/server/public/assets'],
    'package root' => ['.', ''],
    'unset root' => [null, ''],
]);

it('resolves the production application directory through current rather than the selected checkout', function (?string $root, string $suffix): void {
    $project = new Project(['apps' => fixture_apps($root, $root === '.' ? ProjectType::LaravelPackage : ProjectType::LaravelApp)]);
    $node = new Node;
    $node->setRelation('roles', collect([new NodeRole(['role' => RoleName::AppProd])]));
    $instance = new Instance([
        'checkout_path' => '/srv/production/site/releases/20260601',
        'production_home' => '/srv/production/site',
    ]);
    $instance->setRelation('project', $project);
    $instance->setRelation('node', $node);

    expect($instance->applicationDirectory())->toBe('/srv/production/site/current'.$suffix);
})->with([
    'root public' => ['public', ''],
    'nested public' => ['server/web/public', '/server/web'],
    'no public suffix' => ['server/web', '/server/web'],
    'package root' => ['.', ''],
    'unset root' => [null, ''],
]);

it('uses the Instance override for the application directory without changing the stored apps', function (RoleName $role, string $expected): void {
    $project = new Project(['apps' => fixture_apps('apps/other/public')]);
    $node = new Node;
    $node->setRelation('roles', collect([new NodeRole(['role' => $role])]));
    $instance = new Instance([
        'app_overrides' => fixture_app_overrides('server/web/public'),
        'checkout_path' => '/srv/checkouts/site',
        'production_home' => '/srv/production/site',
    ]);
    $instance->setRelation('project', $project);
    $instance->setRelation('node', $node);

    expect($instance->applicationDirectory())->toBe($expected);
    expect($instance->app_overrides)->toBe(fixture_app_overrides('server/web/public'));
    expect($project->apps)->toBe(fixture_apps('apps/other/public'));
})->with([
    'development' => [RoleName::AppDev, '/srv/checkouts/site/server/web'],
    'production' => [RoleName::AppProd, '/srv/production/site/current/server/web'],
]);
