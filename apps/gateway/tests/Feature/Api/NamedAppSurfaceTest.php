<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceState;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Processes\ProcessTargetType;
use App\Domain\Schedules\ScheduleTargetResolver;
use App\Domain\Schedules\ScheduleTargetType;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Tests\Support\Schedules\FakeScheduleRuntimeAccountResolver;

beforeEach(function (): void {
    $this->gateway = $this->markAsGateway(Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.44.0.1',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.1']);
    $this->fakeRepositoryBranches();
});

/** @return list<array{name: string, path: string, web_root: ?string, type: string}> */
function named_app_surface_apps(): array
{
    return [
        ['name' => 'web', 'path' => 'apps/site', 'web_root' => 'public', 'type' => 'laravel-app'],
        ['name' => 'docs', 'path' => 'apps/docs', 'web_root' => 'public', 'type' => 'laravel-app'],
    ];
}

function named_app_surface_instance(): Instance
{
    $project = Project::query()->create([
        'name' => 'Drift', 'slug' => 'drift', 'repository_url' => 'https://example.test/drift.git',
        'default_branch' => 'main', 'apps' => named_app_surface_apps(),
    ]);
    $node = Node::query()->create([
        'name' => 'drift-dev', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'user' => 'orbit',
        'public_ssh_host' => '192.0.2.30', 'wireguard_ip' => '10.44.0.30',
    ]);
    $node->roles()->create(['role' => 'app-dev', 'status' => LifecycleStatus::Active]);

    return Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $node->id, 'name' => 'main', 'checkout_path' => '/srv/orbit/drift/main',
        'provisioning_step' => 'active', 'status' => InstanceState::Active,
        'app_runtime' => ['docs' => ['laravel' => true, 'php_version' => '8.5'], 'web' => ['laravel' => true, 'php_version' => '8.5']],
    ])->load('node');
}

it('named app create returns the apps of a two-app Project by name', function (): void {
    [$web, $docs] = named_app_surface_apps();

    $this->postJson('/api/v1/projects', [
        'slug' => 'drift',
        'type' => 'monorepo',
        'repository_url' => 'https://example.test/drift.git',
        'default_branch' => 'main',
        'apps' => [$web, $docs],
    ])
        ->assertCreated()
        ->assertJsonPath('data.apps', [$docs, $web])
        ->assertJsonMissingPath('data.root');

    expect(Project::query()->sole()->apps)->toBe([$docs, $web]);
});

it('named app requests refuse the removed root field', function (string $path, array $payload, string $replacement): void {
    $this->postJson($path, $payload)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertJsonPath('error.details.root.0', "The root field was removed. Send {$replacement} instead.");

    expect(Project::query()->count())->toBe(0);
})->with([
    'Project create' => ['/api/v1/projects', ['slug' => 'drift', 'type' => 'laravel-app', 'repository_url' => 'https://example.test/drift.git', 'root' => 'public'], 'apps'],
    'Instance create' => ['/api/v1/instances', ['project_id' => 1, 'node_id' => 1, 'name' => 'dev', 'root' => 'public'], 'app_overrides'],
]);

it('named app definitions need an app on a multi-app Project and keep the one they name', function (string $kind, array $payload): void {
    $project = named_app_surface_instance()->project;

    $this->postJson("/api/v1/projects/{$project->id}/{$kind}-definitions", $payload)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'app.required');
    $this->postJson("/api/v1/projects/{$project->id}/{$kind}-definitions", [...$payload, 'app' => 'blog'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'app.not_found');
    $this->postJson("/api/v1/projects/{$project->id}/{$kind}-definitions", [...$payload, 'app' => 'docs'])
        ->assertCreated()
        ->assertJsonPath('data.app', 'docs');
    $this->putJson("/api/v1/projects/{$project->id}/{$kind}-definitions/worker", $payload)
        ->assertOk()
        ->assertJsonPath('data.app', 'docs');
})->with([
    'process' => ['process', ['name' => 'worker', 'environments' => ['production'], 'spec' => ['runtime' => 'systemd', 'command' => ['/usr/bin/php', 'artisan', 'queue:work']]]],
    'schedule' => ['schedule', ['name' => 'worker', 'environments' => ['production'], 'spec' => ['command' => 'php artisan schedule:run', 'calendar' => 'hourly', 'timeout_seconds' => 3600]]],
]);

it('named app Processes run in their app directory and read its environment file', function (): void {
    $instance = named_app_surface_instance();
    $targets = app(ProcessTargetResolver::class);

    expect(fn () => $targets->resolve(ProcessTargetType::Instance, $instance->id))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('app.required'));
    expect(fn () => $targets->resolve(ProcessTargetType::Node, $instance->node_id, 'docs'))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('app.selector_unsupported'));

    $target = $targets->resolve(ProcessTargetType::Instance, $instance->id, 'docs');

    expect($target->app)->toBe('docs')
        ->and($target->defaultWorkingDirectory)->toBe('/srv/orbit/drift/main/apps/docs')
        ->and($target->environmentFile)->toBe('/srv/orbit/drift/main/apps/docs/.env');
});

it('named app Schedules run in their app directory', function (): void {
    $instance = named_app_surface_instance();
    $targets = new ScheduleTargetResolver(new FakeScheduleRuntimeAccountResolver);

    expect(fn () => $targets->resolve(ScheduleTargetType::Instance, $instance->id))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('app.required'));
    expect(fn () => $targets->resolve(ScheduleTargetType::Node, $instance->node_id, 'docs'))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('app.selector_unsupported'));

    expect($targets->resolve(ScheduleTargetType::Instance, $instance->id, 'docs')->workingDirectory)
        ->toBe('/srv/orbit/drift/main/apps/docs');
});

it('named app overrides cannot change once the Instance exists', function (): void {
    $instance = named_app_surface_instance();

    $this->patchJson("/api/v1/projects/{$instance->project_id}", ['apps' => [named_app_surface_apps()[0]]])
        ->assertConflict()
        ->assertJsonPath('error.code', 'project.apps_locked_by_instances');

    expect($instance->project->refresh()->apps)->toHaveCount(2);
});
