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

it('keeps non-Laravel dependency trees at repository scope and uses the effective Laravel root', function (ProjectType $type, ?bool $laravel, ?string $override, string $expected): void {
    $project = new Project(['type' => $type, 'root' => 'apps/site/public']);
    $node = new Node;
    $node->setRelation('roles', collect([new NodeRole(['role' => RoleName::AppDev])]));
    $instance = new Instance(['checkout_path' => '/srv/repository', 'root' => $override, 'source_is_laravel' => $laravel]);
    $instance->setRelation('project', $project);
    $instance->setRelation('node', $node);

    expect($instance->dependencyDirectory())->toBe($expected);
})->with([
    'Laravel inherited' => [ProjectType::LaravelApp, null, null, '/srv/repository/apps/site'],
    'Laravel override public' => [ProjectType::LaravelApp, true, 'public', '/srv/repository'],
    'Laravel monorepo' => [ProjectType::Monorepo, true, null, '/srv/repository/apps/site'],
    'unclassified monorepo' => [ProjectType::Monorepo, null, null, '/srv/repository'],
    'package' => [ProjectType::LaravelPackage, false, null, '/srv/repository'],
]);

it('resolves the application directory from the inherited web root', function (?string $root, string $suffix): void {
    $project = new Project(['root' => $root]);
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
    $project = new Project(['root' => $root]);
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

it('uses the Instance override for the application directory without changing the stored roots', function (RoleName $role, string $expected): void {
    $project = new Project(['root' => 'apps/other/public']);
    $node = new Node;
    $node->setRelation('roles', collect([new NodeRole(['role' => $role])]));
    $instance = new Instance([
        'root' => 'server/web/public',
        'checkout_path' => '/srv/checkouts/site',
        'production_home' => '/srv/production/site',
    ]);
    $instance->setRelation('project', $project);
    $instance->setRelation('node', $node);

    expect($instance->applicationDirectory())->toBe($expected);
    expect($instance->root)->toBe('server/web/public');
    expect($project->root)->toBe('apps/other/public');
})->with([
    'development' => [RoleName::AppDev, '/srv/checkouts/site/server/web'],
    'production' => [RoleName::AppProd, '/srv/production/site/current/server/web'],
]);
