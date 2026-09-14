<?php

declare(strict_types=1);

use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Route;

it('declares node access scope on every active-peer API route', function (): void {
    $protectedRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(static fn (IlluminateRoute $route): bool => str_starts_with($route->uri(), 'api/v1/'))
        ->filter(
            static fn (IlluminateRoute $route): bool => in_array(
                RequireActiveWireGuardPeer::class,
                $route->gatherMiddleware(),
                strict: true,
            ),
        )
        ->values();

    expect($protectedRoutes)->not->toBeEmpty();

    $actualScopes = [];

    foreach ($protectedRoutes as $route) {
        expect($route->gatherMiddleware())
            ->toContain(RequireNodeAccess::class);

        $controllerClass = $route->getControllerClass();
        $method = $route->getActionMethod();

        expect($controllerClass)->toBeString();

        $classAttributes = new ReflectionClass($controllerClass)
            ->getAttributes(RequiresNodeAccess::class);
        $methodAttributes = new ReflectionMethod($controllerClass, $method)
            ->getAttributes(RequiresNodeAccess::class);

        expect(count($classAttributes) + count($methodAttributes))
            ->toBe(1, "Route [{$route->getName()}] must declare exactly one RequiresNodeAccess attribute.");

        $attribute = $methodAttributes[0] ?? $classAttributes[0];
        $actualScopes[$route->getName()][] = $attribute->newInstance()->servingNode;
    }

    ksort($actualScopes);
    $actualScopes = array_map(static function (array $scopes): ServingNode|array {
        usort(
            $scopes,
            static fn (ServingNode $left, ServingNode $right): int => $left->name <=> $right->name,
        );

        return count($scopes) === 1 ? $scopes[0] : $scopes;
    }, $actualScopes);

    $expectedScopes = [
        'activity:list' => ServingNode::Gateway,
        'activity:show' => ServingNode::Gateway,
        'app:create' => ServingNode::Gateway,
        'app:destroy' => ServingNode::AppOwning,
        'app:list' => ServingNode::Collection,
        'app:show' => ServingNode::AppOwning,
        'cluster:create' => ServingNode::Gateway,
        'cluster:destroy' => ServingNode::ClusterOwning,
        'cluster:list' => ServingNode::Collection,
        'cluster:node:add' => ServingNode::Target,
        'cluster:node:remove' => ServingNode::Target,
        'cluster:router:set' => ServingNode::Target,
        'cluster:router:unset' => ServingNode::ClusterOwning,
        'cluster:show' => ServingNode::ClusterOwning,
        'cluster:update' => ServingNode::ClusterOwning,
        'database:create' => ServingNode::Gateway,
        'database:destroy' => ServingNode::Gateway,
        'database:list' => ServingNode::Gateway,
        'database:show' => ServingNode::Gateway,
        'database:update' => ServingNode::Gateway,
        'doctor' => ServingNode::Collection,
        'env:import' => ServingNode::EnvironmentInstanceOwning,
        'env:sync' => ServingNode::EnvironmentInstanceOwning,
        'env:update' => ServingNode::EnvironmentInstanceOwning,
        'firewall:allow' => ServingNode::Target,
        'firewall:deny' => ServingNode::Target,
        'firewall:list' => ServingNode::Target,
        'firewall:remove' => ServingNode::Target,
        'herdr:observe' => ServingNode::HerdrSessionOwning,
        'herdr:session:adopt' => ServingNode::HerdrSessionOwning,
        'herdr:session:create' => ServingNode::HerdrSessionOwning,
        'herdr:session:destroy' => ServingNode::HerdrSessionOwning,
        'herdr:session:list' => ServingNode::HerdrSessionOwning,
        'herdr:session:restart' => ServingNode::HerdrSessionOwning,
        'herdr:session:show' => ServingNode::HerdrSessionOwning,
        'instance:clone' => ServingNode::CandidateClone,
        'instance:create' => ServingNode::InstanceOwning,
        'instance:database:add' => ServingNode::EnvironmentInstanceOwning,
        'instance:database:remove' => ServingNode::EnvironmentInstanceOwning,
        'instance:deploy' => ServingNode::InstanceOwning,
        'instance:deploy-step:create' => ServingNode::InstanceOwning,
        'instance:deploy-step:destroy' => ServingNode::InstanceOwning,
        'instance:deploy-step:list' => ServingNode::InstanceOwning,
        'instance:deploy-step:update' => ServingNode::InstanceOwning,
        'instance:deployment-config:show' => ServingNode::InstanceOwning,
        'instance:deployment-config:update' => ServingNode::InstanceOwning,
        'instance:deployment-layout:prepare' => ServingNode::InstanceOwning,
        'instance:destroy' => ServingNode::InstanceOwning,
        'instance:list' => ServingNode::Collection,
        'instance:register' => ServingNode::Caller,
        'instance:release:list' => ServingNode::InstanceOwning,
        'instance:rollback' => ServingNode::InstanceOwning,
        'instance:show' => ServingNode::InstanceOwning,
        'instance:update' => ServingNode::InstanceOwning,
        'metrics:credentials' => ServingNode::Gateway,
        'metrics:credentials:reset' => ServingNode::Gateway,
        'metrics:disable' => ServingNode::Gateway,
        'metrics:enable' => ServingNode::Gateway,
        'metrics:exporter:disable' => ServingNode::Gateway,
        'metrics:exporter:enable' => ServingNode::Gateway,
        'metrics:grafana:authorize' => ServingNode::Gateway,
        'metrics:status' => ServingNode::Gateway,
        'node:access:add' => ServingNode::Gateway,
        'node:access:remove' => ServingNode::Gateway,
        'node:add' => ServingNode::Gateway,
        'node:list' => ServingNode::Collection,
        'node:remove' => ServingNode::Target,
        'node:role:add' => ServingNode::RoleMutation,
        'node:role:list' => ServingNode::Target,
        'node:role:remove' => ServingNode::RoleMutation,
        'node:settings' => ServingNode::Target,
        'node:show' => ServingNode::Target,
        'process:create' => [ServingNode::AppOwning, ServingNode::ProcessOwning],
        'process:destroy' => [ServingNode::AppOwning, ServingNode::ProcessOwning],
        'process:list' => [ServingNode::AppOwning, ServingNode::ProcessOwning],
        'process:logs' => ServingNode::ProcessOwning,
        'process:restart' => ServingNode::ProcessOwning,
        'process:show' => ServingNode::AppOwning,
        'process:start' => ServingNode::ProcessOwning,
        'process:stop' => ServingNode::ProcessOwning,
        'process:update' => ServingNode::AppOwning,
        'route:create' => ServingNode::RouteOwning,
        'route:destroy' => ServingNode::RouteOwning,
        'route:list' => ServingNode::Collection,
        'route:show' => ServingNode::RouteOwning,
        'route:target:set' => ServingNode::RouteOwning,
        'route:target:unset' => ServingNode::RouteOwning,
        'route:update' => ServingNode::RouteOwning,
        'runtime-activation:app-instance' => ServingNode::AppInstanceHost,
        'schedule:complete' => ServingNode::ScheduleHost,
        'schedule:create' => [ServingNode::AppOwning, ServingNode::ScheduleOwning],
        'schedule:destroy' => [ServingNode::AppOwning, ServingNode::ScheduleOwning],
        'schedule:enable' => ServingNode::ScheduleOwning,
        'schedule:list' => [ServingNode::AppOwning, ServingNode::Collection],
        'schedule:logs' => ServingNode::ScheduleOwning,
        'schedule:run' => ServingNode::ScheduleOwning,
        'schedule:show' => [ServingNode::AppOwning, ServingNode::ScheduleOwning],
        'schedule:update' => ServingNode::AppOwning,
        'tool:install' => ServingNode::ToolOwning,
        'tool:list' => ServingNode::ToolOwning,
        'tool:manager:list' => ServingNode::ToolOwning,
        'tool:remove' => ServingNode::ToolOwning,
        'tool:show' => ServingNode::ToolOwning,
        'tool:update' => ServingNode::ToolOwning,
    ];

    expect($actualScopes)->toBe($expectedScopes);
});

it('registers every Route endpoint with its exact HTTP contract', function (): void {
    $actual = collect(Route::getRoutes()->getRoutes())
        ->filter(static fn (IlluminateRoute $route): bool => str_starts_with($route->getName() ?? '', 'route:'))
        ->mapWithKeys(static fn (IlluminateRoute $route): array => [
            $route->getName() => [$route->methods()[0], $route->uri()],
        ])
        ->all();
    ksort($actual);

    expect($actual)->toBe([
        'route:create' => ['POST', 'api/v1/routes'],
        'route:destroy' => ['DELETE', 'api/v1/routes/{route}'],
        'route:list' => ['GET', 'api/v1/routes'],
        'route:show' => ['GET', 'api/v1/routes/{route}'],
        'route:target:set' => ['PUT', 'api/v1/routes/{route}/target'],
        'route:target:unset' => ['DELETE', 'api/v1/routes/{route}/target'],
        'route:update' => ['PATCH', 'api/v1/routes/{route}'],
    ]);
});

it('registers every Tool route with its exact HTTP contract', function (): void {
    $actual = collect(Route::getRoutes()->getRoutes())
        ->filter(static fn (IlluminateRoute $route): bool => str_starts_with($route->getName() ?? '', 'tool:'))
        ->mapWithKeys(static fn (IlluminateRoute $route): array => [
            $route->getName() => [
                $route->methods()[0],
                $route->uri(),
            ],
        ])
        ->all();
    ksort($actual);

    expect($actual)->toBe([
        'tool:install' => ['POST', 'api/v1/tools'],
        'tool:list' => ['GET', 'api/v1/tools'],
        'tool:manager:list' => ['GET', 'api/v1/tool-managers'],
        'tool:remove' => ['DELETE', 'api/v1/tools/{tool}'],
        'tool:show' => ['GET', 'api/v1/tools/{tool}'],
        'tool:update' => ['POST', 'api/v1/tools/{tool}/update'],
    ]);
});

it('constrains every route-bound Tool parameter to numeric IDs', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(static fn (IlluminateRoute $route): bool => in_array(
            $route->getName(),
            [
                'tool:show',
                'tool:update',
                'tool:remove',
            ],
            strict: true,
        ));

    expect($routes)->toHaveCount(3);

    foreach ($routes as $route) {
        expect($route->wheres['tool'] ?? null)
            ->toBe('[0-9]+', "Route [{$route->getName()}] must constrain tool IDs.");
    }
});

it('keeps only bootstrap routes outside peer and node access middleware', function (): void {
    $apiRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(static fn (IlluminateRoute $route): bool => str_starts_with($route->uri(), 'api/v1/'))
        ->values();

    foreach ($apiRoutes as $route) {
        $middleware = $route->gatherMiddleware();

        if (in_array($route->getName(), ['gateway:status', 'gateway:trust'], strict: true)) {
            expect($middleware)
                ->not->toContain(RequireActiveWireGuardPeer::class)
                ->not->toContain(RequireNodeAccess::class);

            continue;
        }

        expect($middleware)
            ->toContain(RequireActiveWireGuardPeer::class)
            ->toContain(RequireNodeAccess::class);
    }

    expect($apiRoutes->map(static fn (IlluminateRoute $route): ?string => $route->getName())->all())
        ->toContain('gateway:status', 'gateway:trust');
});
