<?php

declare(strict_types=1);

use App\Actions\Routes\CreateRouteAction;
use App\Actions\Routes\RemoveRouteAction;
use App\Actions\Routes\UpdateRouteAction;
use App\Data\Routes\CreateRouteData;
use App\Data\Routes\UpdateRouteData;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteRemovalProjector;
use App\Domain\Shared\LifecycleStatus;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeRouteRemovalProjector;

beforeEach(function (): void {
    app()->instance(RouteRemovalProjector::class, new FakeRouteRemovalProjector);

    $this->node = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20',
        'wireguard_ip' => '10.44.0.3',
        'tld' => 'dev.orbit',
    ]);
    $this->orbitApp = OrbitApp::query()->create([
        'name' => 'Docs',
        'slug' => 'docs',
        'repository_url' => 'git@example.test:docs.git',
        'root' => 'public',
    ]);
});

function route_record_pending(int $appId, int $nodeId): Route
{
    return Route::query()->create([
        'app_id' => $appId,
        'node_id' => $nodeId,
        'domain' => 'docs.orbit',
        'provenance' => 'explicit',
        'publication' => RoutePublication::Private,
        'status' => 'pending',
    ]);
}

describe('Route record events', function (): void {
    it('broadcasts route.created when a new App route is created', function (): void {
        Event::fake([RecordBroadcast::class]);

        $data = new CreateRouteData(
            domain: 'docs.orbit',
            publication: RoutePublication::Private,
            appId: $this->orbitApp->id,
            appInstanceId: AppInstance::query()->create([
                'app_id' => $this->orbitApp->id,
                'node_id' => $this->node->id,
                'name' => 'docs',
                'checkout_path' => '/srv/docs',
                'status' => AppInstanceState::Active,
            ])->id,
        );

        $result = app(CreateRouteAction::class)->execute($data);

        expect($result['created'])->toBeTrue();
        Event::assertDispatched(
            RecordBroadcast::class,
            fn (RecordBroadcast $event): bool => $event->type === RecordEventType::RouteCreated
                && $event->id === $result['route']->id
                && $event->data['domain'] === 'docs.orbit',
        );
    });

    it('broadcasts route.updated when the domain changes', function (): void {
        $route = route_record_pending($this->orbitApp->id, $this->node->id);

        Event::fake([RecordBroadcast::class]);

        $result = app(UpdateRouteAction::class)->execute($route, new UpdateRouteData(
            domainProvided: true,
            domain: 'docs-renamed.orbit',
            publicationProvided: false,
            publication: null,
        ));

        Event::assertDispatched(
            RecordBroadcast::class,
            fn (RecordBroadcast $event): bool => $event->type === RecordEventType::RouteUpdated
                && $event->id === $result->id
                && $event->data['domain'] === 'docs-renamed.orbit',
        );
    });

    it('broadcasts route.deleted with a minimal snapshot when a route is removed', function (): void {
        $route = route_record_pending($this->orbitApp->id, $this->node->id);

        Event::fake([RecordBroadcast::class]);

        app(RemoveRouteAction::class)->execute($route);

        Event::assertDispatched(
            RecordBroadcast::class,
            fn (RecordBroadcast $event): bool => $event->type === RecordEventType::RouteDeleted
                && $event->id === $route->id
                && $event->data === ['id' => $route->id, 'domain' => 'docs.orbit'],
        );
    });
});
