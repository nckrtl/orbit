<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceState;
use App\Domain\Schedules\ScheduleTargetResolver;
use App\Domain\Schedules\ScheduleTargetType;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Tests\Support\Schedules\FakeScheduleRuntimeAccountResolver;

beforeEach(function (): void {
    $this->accounts = new FakeScheduleRuntimeAccountResolver;
    $this->resolver = new ScheduleTargetResolver($this->accounts);
    $this->node = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.3',
    ]);
    $this->node->roles()->create(['role' => 'app-dev', 'status' => LifecycleStatus::Active]);
    $project = Project::query()->create([
        'name' => 'Docs',
        'slug' => 'docs',
        'repository_url' => 'git@example.test:docs.git',
        'apps' => fixture_apps(null),
    ]);
    $this->instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $this->node->id,
        'name' => 'main',
        'environment' => 'development',
        'checkout_path' => '/srv/apps/docs',
        'provisioning_step' => 'active',
        'status' => InstanceState::Active,
    ]);
});

it('derives the Node and development Instance contexts', function (): void {
    $node = $this->resolver->resolve(ScheduleTargetType::Node, $this->node->id);
    $development = $this->resolver->resolve(ScheduleTargetType::Instance, $this->instance->id);

    expect($node->node->is($this->node))->toBeTrue()
        ->and($node->user)->toBe('orbit')
        ->and($node->home)->toBe('/home/orbit')
        ->and($node->workingDirectory)->toBe('/home/orbit')
        ->and($node->shell)->toBe('/bin/bash')
        ->and($node->loginShell)->toBeTrue()
        ->and($development->node->is($this->node))->toBeTrue()
        ->and($development->user)->toBe('orbit')
        ->and($development->home)->toBe('/home/orbit')
        ->and($development->workingDirectory)->toBe('/srv/apps/docs')
        ->and($development->loginShell)->toBeTrue();
});

it('derives the stable production current context with fixed non-login bash', function (): void {
    $this->node->roles()->where('role', 'app-dev')->delete();
    $this->node->roles()->create(['role' => 'app-prod', 'status' => LifecycleStatus::Active]);
    $this->instance->update([
        'checkout_path' => '/home/docs/releases/20260911',
        'production_user' => 'docs',
        'production_home' => '/home/docs',
    ]);

    $target = $this->resolver->resolve(ScheduleTargetType::Instance, $this->instance->id);

    expect($target->user)->toBe('docs')
        ->and($target->home)->toBe('/home/docs')
        ->and($target->workingDirectory)->toBe('/home/docs/current')
        ->and($target->shell)->toBe('/bin/bash')
        ->and($target->loginShell)->toBeFalse();
});

it('runs Instance schedules in the application directory', function (string $root, bool $laravel, string $suffix, bool $production): void {
    $this->instance->update(['app_overrides' => fixture_app_overrides($root), 'source_is_laravel' => $laravel]);
    if ($production) {
        $this->node->roles()->where('role', 'app-dev')->delete();
        $this->node->roles()->create(['role' => 'app-prod', 'status' => LifecycleStatus::Active]);
        $this->instance->update(['checkout_path' => '/home/docs/releases/initial', 'production_user' => 'docs', 'production_home' => '/home/docs']);
    }

    $target = $this->resolver->resolve(ScheduleTargetType::Instance, $this->instance->id);

    expect($target->workingDirectory)->toBe(($production ? '/home/docs/current' : '/srv/apps/docs').$suffix);
})->with([
    'nested development' => ['server/web/public', true, '/server/web', false],
    'root public development' => ['public', true, '', false],
    'non Laravel development' => ['server/web/public', false, '', false],
    'nested production' => ['server/web/public', true, '/server/web', true],
    'root public production' => ['public', true, '', true],
]);

it('maps unavailable targets and account inspection to the stable catalog', function (): void {
    $this->accounts->unavailable = true;

    expect(fn () => $this->resolver->resolve(ScheduleTargetType::Instance, $this->instance->id))
        ->toThrow(fn (ResourceOperationException $exception): bool => $exception->errorCode === 'schedule.target_unavailable');

    $this->accounts->unavailable = false;
    $this->instance->update(['status' => InstanceState::Reserved]);

    expect(fn () => $this->resolver->resolve(ScheduleTargetType::Instance, $this->instance->id))
        ->toThrow(fn (ResourceOperationException $exception): bool => $exception->errorCode === 'schedule.target_unavailable');
});

it('rejects missing and invalid target identities before runtime mutation', function (): void {
    expect(fn () => $this->resolver->resolve(ScheduleTargetType::Node, 0))
        ->toThrow(fn (ResourceOperationException $exception): bool => $exception->errorCode === 'schedule.target_invalid');
    expect(fn () => $this->resolver->resolve(ScheduleTargetType::Node, 9999))
        ->toThrow(fn (ResourceOperationException $exception): bool => $exception->errorCode === 'schedule.target_invalid');
});
