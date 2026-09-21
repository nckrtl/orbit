<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentContextResolver;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Route;

it('resolves env context when a finished public Route keeps a terminal public-edge step', function (RouteReplacementStep $step): void {
    [$instance, $route] = env_context_owner_fixture();
    $route->update([
        'publication' => RoutePublication::Public,
        'replacement_step' => $step,
    ]);

    $context = new AppInstanceEnvironmentContextResolver()->resolve($instance, true);

    expect($context->appInstanceId)->toBe($instance->id)
        ->and($context->routeId)->toBe($route->id)
        ->and($context->routeDomain)->toBe($route->domain);
})->with([
    'public-activated' => RouteReplacementStep::PublicActivated,
    'ingress-firewall' => RouteReplacementStep::IngressFirewall,
]);

it('refuses env context when a public Route is mid-activation', function (RouteReplacementStep $step): void {
    [$instance, $route] = env_context_owner_fixture();
    $route->update([
        'publication' => RoutePublication::Public,
        'replacement_step' => $step,
    ]);

    expect(fn () => new AppInstanceEnvironmentContextResolver()->resolve($instance, true))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)
                ->toBe('env.owner_unavailable')
                ->and($exception->status)
                ->toBe(409);
        });
})->with([
    'ingress-certificate' => RouteReplacementStep::IngressCertificate,
    'ingress-caddy' => RouteReplacementStep::IngressCaddy,
    'public-edge-verified' => RouteReplacementStep::PublicEdgeVerified,
    'reserved' => RouteReplacementStep::Reserved,
]);

/** @return array{AppInstance, Route} */
function env_context_owner_fixture(): array
{
    $node = Node::query()->create([
        'name' => 'env-context-owner',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.211',
        'wireguard_ip' => '10.44.0.211',
        'user' => 'orbit',
    ]);
    $app = OrbitApp::query()->create([
        'name' => 'Env context',
        'slug' => 'env-context',
        'repository_url' => 'https://example.test/env-context.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'default',
        'environment' => 'development',
        'checkout_path' => '/srv/orbit/env-context/default',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
        'status' => AppInstanceState::Active,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'domain' => 'env-context.example.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);

    return [$instance->fresh(['node']), $route->fresh()];
}
