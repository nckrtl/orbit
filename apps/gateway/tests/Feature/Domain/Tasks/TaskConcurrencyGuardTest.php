<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskCeilings;
use App\Domain\Tasks\TaskConcurrencyGuard;
use App\Domain\Tasks\TaskGroupStatus;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\TaskGroup;

function guard_app(string $slug): OrbitApp
{
    return OrbitApp::query()->create([
        'name' => $slug,
        'slug' => $slug,
        'repository_url' => "git@example.test:{$slug}.git",
        'default_branch' => 'main',
    ]);
}

function guard_node(string $name, string $ip): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => $ip,
        'wireguard_ip' => $ip,
    ]);
}

function guard_instance(OrbitApp $app, Node $node, string $name): AppInstance
{
    return AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => $name,
        'checkout_path' => "/tmp/guard-{$app->slug}-{$name}",
        'status' => 'reserved',
    ]);
}

function guard_group(OrbitApp $app, string $title, TaskGroupStatus $status, ?AppInstance $instance = null): TaskGroup
{
    $group = TaskGroup::query()->create([
        'app_id' => $app->id,
        'title' => $title,
        'brief' => "{$title} brief",
        'status' => $status,
    ]);

    if ($instance instanceof AppInstance) {
        $group->taskable()->associate($instance);
        $group->save();
    }

    return $group->fresh(['taskable']) ?? $group;
}

it('allows more than three groups on the same App when no Instance is assigned', function (): void {
    $app = guard_app('open-app');
    $guard = app(TaskConcurrencyGuard::class);

    foreach (['One', 'Two', 'Three'] as $title) {
        guard_group($app, $title, TaskGroupStatus::Reserved);
    }

    $fourth = guard_group($app, 'Four', TaskGroupStatus::Queued);

    expect($guard->canActivate($fourth))->toBeTrue();
});

it('allows more than three assigned groups on the same App while the Node is under the ceiling', function (): void {
    $app = guard_app('busy-app');
    $node = guard_node('busy-node', '10.44.0.97');
    $guard = app(TaskConcurrencyGuard::class);

    foreach (range(1, 3) as $index) {
        guard_group(
            $app,
            "Active {$index}",
            TaskGroupStatus::Running,
            guard_instance($app, $node, "slot-{$index}"),
        );
    }

    $fourth = guard_group(
        $app,
        'Four',
        TaskGroupStatus::Queued,
        guard_instance($app, $node, 'slot-4'),
    );

    expect($guard->canActivate($fourth))->toBeTrue()
        ->and($guard->activeForNode($node->id))->toBe(3);
});

it('refuses activation when the Node already has ten active groups from the same App', function (): void {
    $app = guard_app('full-node-app');
    $node = guard_node('full-guard-node', '10.44.0.98');
    $guard = app(TaskConcurrencyGuard::class);

    foreach (range(1, TaskCeilings::PerNode) as $index) {
        guard_group(
            $app,
            "Fill {$index}",
            TaskGroupStatus::Running,
            guard_instance($app, $node, "fill-{$index}"),
        );
    }

    $queued = guard_group(
        $app,
        'Overflow',
        TaskGroupStatus::Queued,
        guard_instance($app, $node, 'overflow'),
    );

    expect($guard->activeForNode($node->id))->toBe(TaskCeilings::PerNode)
        ->and($guard->canActivate($queued))->toBeFalse();
});
