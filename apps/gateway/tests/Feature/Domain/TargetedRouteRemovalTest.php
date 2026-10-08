<?php

declare(strict_types=1);

use App\Actions\Routes\RemoveRouteAction;
use App\Domain\Instances\InstanceState;
use App\Domain\Routes\RouteRemovalProjector;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use App\Models\RouteTarget;
use Tests\Support\FakeRouteRemovalProjector;

beforeEach(function (): void {
    $this->projector = new FakeRouteRemovalProjector;
    app()->instance(RouteRemovalProjector::class, $this->projector);
});

describe('targeted Route removal', function (): void {
    it('withdraws a published pending Route before it deletes the record', function (): void {
        $route = targeted_route_removal_route(InstanceState::SourceResolved);
        $instanceId = $route->targets->sole()->instance_id;

        $removed = app(RemoveRouteAction::class)->execute($route);

        expect($removed->id)->toBe($route->id)
            ->and(Route::query()->whereKey($route->id)->exists())->toBeFalse()
            ->and(RouteTarget::query()->where('route_id', $route->id)->exists())->toBeFalse()
            ->and(Instance::query()->findOrFail($instanceId)->status)->toBe(InstanceState::SourceResolved)
            ->and($this->projector->events)->toBe(['dns', 'caddy', 'php', 'certificates', 'firewall'])
            ->and(array_unique($this->projector->routeIds))->toBe([$route->id]);

        // Every step sees the withdrawn publication, so each build renders the Route's sites away.
        foreach ($this->projector->storedStates as $state) {
            expect($state)->toBe(['status' => RouteStatus::Retiring, 'sites_published' => false, 'targets' => 1]);
        }
    });

    it('retains targeted failure evidence at each boundary and resumes until the record is gone', function (
        string $failure,
    ): void {
        $route = targeted_route_removal_route(InstanceState::SourceResolved);
        $this->projector->failures[$failure] = 1;

        expect(fn () => app(RemoveRouteAction::class)->execute($route))
            ->toThrow(ResourceOperationException::class, "Injected {$failure} failure.");

        expect($route->refresh()->status)->toBe(RouteStatus::Failed)
            ->and($route->failed_step)->toBe("targeted:{$failure}")
            ->and($route->error_code)->toBe("route.test_{$failure}")
            ->and($route->sites_published)->toBeFalse()
            ->and($route->targets()->count())->toBe(1);

        $completed = count($this->projector->events);
        app(RemoveRouteAction::class)->execute($route);

        expect(Route::query()->whereKey($route->id)->exists())->toBeFalse()
            ->and(array_slice($this->projector->events, $completed))
            ->toBe(['dns', 'caddy', 'php', 'certificates', 'firewall']);
    })->with([
        'DNS' => ['dns'],
        'Caddy' => ['caddy'],
        'PHP-FPM' => ['php'],
        'certificates' => ['certificates'],
        'firewall' => ['firewall'],
    ]);

    it('refuses an active target before it changes or withdraws anything', function (): void {
        $route = targeted_route_removal_route(InstanceState::Active);

        expect(fn () => app(RemoveRouteAction::class)->execute($route))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('route.target_conflict');
            });

        expect($route->refresh()->status)->toBe(RouteStatus::Pending)
            ->and($route->sites_published)->toBeTrue()
            ->and($this->projector->events)->toBe([]);
    });

    it('refuses an untargeted removal failure that gained a target, without withdrawing again', function (): void {
        $route = targeted_route_removal_route(InstanceState::SourceResolved);
        $route->update(['status' => RouteStatus::Failed, 'failed_step' => 'firewall', 'error_code' => 'route.test_firewall']);

        expect(fn () => app(RemoveRouteAction::class)->execute($route))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('env.owner_changed');
            });

        expect($route->fresh())->not->toBeNull()
            ->and($this->projector->events)->toBe([]);
    });
});

function targeted_route_removal_route(InstanceState $state): Route
{
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://example.test/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $node = Node::query()->create([
        'name' => 'beast',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'tld' => 'beast.test',
        'public_ssh_host' => '192.0.2.92',
        'wireguard_ip' => '10.44.0.92',
        'user' => 'orbit',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'task-342',
        'checkout_path' => '/srv/acme/task-342',
        'branch' => 'task-342',
        'starting_commit' => str_repeat('a', 40),
        'status' => $state,
    ]);
    $route = Route::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'domain' => 'task-342.acme.beast.test',
        'provenance' => 'explicit',
        'publication' => 'private',
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->publishSites();

    return $route->refresh()->load('targets');
}
