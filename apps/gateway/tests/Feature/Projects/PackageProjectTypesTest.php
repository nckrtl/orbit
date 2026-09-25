<?php

declare(strict_types=1);

use App\Actions\AppInstances\Dependencies\SelectDependencyInputAction;
use App\Domain\AppInstances\Dependencies\CollectedDependencyFiles;
use App\Domain\AppInstances\Dependencies\DependencyEcosystem;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppInstances\DependencyFilesProgram;
use App\Models\App as OrbitApp;
use App\Models\Node;

beforeEach(function (): void {
    $this->operator = Node::query()->create([
        'name' => 'operator',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
    ]);
    $this->operator = $this->markAsGateway($this->operator);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    $this->fakeRepositoryBranches();
});

it('covers package project types when creating and updating Projects', function (): void {
    foreach ([ProjectType::LaravelPackage, ProjectType::NodePackage] as $type) {
        $created = $this->postJson('/api/v1/projects', [
            'slug' => $type === ProjectType::LaravelPackage ? 'laravel-kit' : 'node-kit',
            'type' => $type->value,
            'repository_url' => 'https://github.com/acme/'.$type->value.'.git',
            'default_branch' => 'main',
            'root' => '.',
        ])->assertCreated()
            ->assertJsonPath('data.type', $type->value)
            ->assertJsonPath('data.root', '.');

        $updatedType = $type === ProjectType::LaravelPackage
            ? ProjectType::NodePackage
            : ProjectType::LaravelPackage;

        $this->patchJson('/api/v1/projects/'.$created->json('data.id'), [
            'type' => $updatedType->value,
        ])->assertOk()
            ->assertJsonPath('data.type', $updatedType->value);
    }
});

it('updates a legacy Project with a null root when root is omitted', function (): void {
    $project = OrbitApp::query()->create([
        'name' => 'legacy-project',
        'slug' => 'legacy-project',
        'type' => ProjectType::LaravelApp,
        'repository_url' => 'https://github.com/acme/legacy-project.git',
        'default_branch' => null,
        'root' => null,
    ]);

    $this->patchJson('/api/v1/projects/'.$project->id, [
        'slug' => 'legacy-renamed',
        'default_branch' => 'stable',
    ])->assertOk()
        ->assertJsonPath('data.slug', 'legacy-renamed')
        ->assertJsonPath('data.default_branch', 'stable')
        ->assertJsonPath('data.root', null);
});

it('rejects a type change when the stored package root is invalid for that type', function (): void {
    $project = OrbitApp::query()->create([
        'name' => 'root-package',
        'slug' => 'root-package',
        'type' => ProjectType::NodePackage,
        'repository_url' => 'https://github.com/acme/root-package.git',
        'default_branch' => 'main',
        'root' => '.',
    ]);

    foreach ([ProjectType::LaravelApp, ProjectType::Monorepo] as $type) {
        $this->patchJson('/api/v1/projects/'.$project->id, [
            'type' => $type->value,
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonPath('error.details.root.0', fn (string $message): bool => $message !== '');

        expect($project->refresh()->type)->toBe(ProjectType::NodePackage)
            ->and($project->root)->toBe('.');
    }
});

it('rejects unknown Project types on create and update', function (): void {
    $this->postJson('/api/v1/projects', [
        'slug' => 'unknown-project',
        'type' => 'desktop-app',
        'repository_url' => 'https://github.com/acme/unknown-project.git',
        'default_branch' => 'main',
        'root' => 'src',
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertJsonPath('error.details.type.0', fn (string $message): bool => $message !== '');

    $project = OrbitApp::query()->create([
        'name' => 'known-project',
        'slug' => 'known-project',
        'type' => ProjectType::LaravelPackage,
        'repository_url' => 'https://github.com/acme/known-project.git',
        'default_branch' => 'main',
        'root' => 'src',
    ]);

    $this->patchJson('/api/v1/projects/'.$project->id, [
        'type' => 'desktop-app',
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertJsonPath('error.details.type.0', fn (string $message): bool => $message !== '');
});

it('selects Composer and Node package manifests and lockfiles without web-root assumptions', function (): void {
    $files = [];
    $hashes = [];
    foreach (DependencyFilesProgram::FILES as $name) {
        $files[$name] = null;
        $hashes[$name] = null;
    }
    $files['composer.json'] = '{"name":"acme/laravel-kit","require":{"illuminate/support":"^13.0"}}';
    $files['composer.lock'] = '{"packages":[]}';

    $composer = new SelectDependencyInputAction()->execute(
        new CollectedDependencyFiles('/checkout', 'main', 'laravel-kit', $files, $hashes, []),
        DependencyEcosystem::Composer,
    );

    expect($composer->manager)->toBe('composer')
        ->and($composer->lockfileName)->toBe('composer.lock');

    $files['composer.json'] = null;
    $files['composer.lock'] = null;
    $files['package.json'] = '{"name":"acme/node-kit","packageManager":"bun@1.2.3","dependencies":{"vite":"^6.0.0"}}';
    $files['bun.lock'] = 'lockfileVersion = 1';

    $javascript = new SelectDependencyInputAction()->execute(
        new CollectedDependencyFiles('/checkout', 'main', 'node-kit', $files, $hashes, []),
        DependencyEcosystem::Npm,
    );

    expect($javascript->manager)->toBe('bun')
        ->and($javascript->lockfileName)->toBe('bun.lock');
});
