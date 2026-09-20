<?php

declare(strict_types=1);

use App\Domain\Projects\ProjectType;
use App\Domain\Projects\ProjectTypeClassifier;

it('classifies the Orbit monorepo by repository identity', function (): void {
    $classifier = new ProjectTypeClassifier;

    expect($classifier->classify([
        'slug' => 'orbit',
        'repository_identity' => ProjectTypeClassifier::OrbitRepositoryIdentity,
        'root' => 'apps/gateway/public',
        'has_production_php' => false,
    ]))->toBe(ProjectType::Monorepo);
});

it('classifies a public web root as a Laravel app', function (string $root): void {
    $classifier = new ProjectTypeClassifier;

    expect($classifier->classify([
        'slug' => 'shop',
        'repository_identity' => 'github.com/acme/shop',
        'root' => $root,
        'has_production_php' => false,
    ]))->toBe(ProjectType::LaravelApp);
})->with(['public', 'web/public']);

it('classifies an existing PHP-FPM placement as a Laravel app', function (): void {
    $classifier = new ProjectTypeClassifier;

    expect($classifier->classify([
        'slug' => 'legacy-site',
        'repository_identity' => 'github.com/acme/legacy-site',
        'root' => null,
        'has_production_php' => true,
    ]))->toBe(ProjectType::LaravelApp);
});

it('classifies remaining repositories as Laravel packages', function (): void {
    $classifier = new ProjectTypeClassifier;

    expect($classifier->classify([
        'slug' => 'support',
        'repository_identity' => 'github.com/acme/support',
        'root' => 'src',
        'has_production_php' => false,
    ]))->toBe(ProjectType::LaravelPackage);
});
