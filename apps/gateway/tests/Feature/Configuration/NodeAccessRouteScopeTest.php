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
        'analytics:credentials' => ServingNode::Gateway,
        'analytics:credentials:set' => ServingNode::Gateway,
        'analytics:credentials:unset' => ServingNode::Gateway,
        'analytics:update' => ServingNode::Gateway,
        'app:create' => ServingNode::Gateway,
        'app:destroy' => ServingNode::AppOwning,
        'app:list' => ServingNode::Collection,
        'app:show' => ServingNode::AppOwning,
        'app:update' => ServingNode::AppOwning,
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
        'database:describe' => ServingNode::Gateway,
        'database:destroy' => ServingNode::Gateway,
        'database:list' => ServingNode::Gateway,
        'database:query' => ServingNode::Gateway,
        'database:schema' => ServingNode::Gateway,
        'database:show' => ServingNode::Gateway,
        'database:tables' => ServingNode::Gateway,
        'database:update' => ServingNode::Gateway,
        'database:user:create' => ServingNode::ProcessOwning,
        'database:user:list' => ServingNode::Gateway,
        'doctor' => ServingNode::Collection,
        'env:import' => ServingNode::EnvironmentInstanceOwning,
        'env:sync' => ServingNode::EnvironmentInstanceOwning,
        'env:update' => ServingNode::EnvironmentInstanceOwning,
        'firewall:allow' => ServingNode::Target,
        'firewall:deny' => ServingNode::Target,
        'firewall:list' => ServingNode::Target,
        'firewall:live:list' => ServingNode::Target,
        'firewall:managed:list' => ServingNode::Target,
        'firewall:remove' => ServingNode::Target,
        'github:app:callback' => ServingNode::Gateway,
        'github:app:destroy' => ServingNode::Gateway,
        'github:app:install' => ServingNode::Gateway,
        'github:app:register' => ServingNode::Gateway,
        'github:app:show' => ServingNode::Gateway,
        'herdr:observe' => ServingNode::HerdrSessionOwning,
        'herdr:session:adopt' => ServingNode::HerdrSessionOwning,
        'herdr:session:create' => ServingNode::HerdrSessionOwning,
        'herdr:session:destroy' => ServingNode::HerdrSessionOwning,
        'herdr:session:list' => ServingNode::HerdrSessionOwning,
        'herdr:session:restart' => ServingNode::HerdrSessionOwning,
        'herdr:session:show' => ServingNode::HerdrSessionOwning,
        'instance:analytics:disable' => ServingNode::InstanceOwning,
        'instance:analytics:enable' => ServingNode::InstanceOwning,
        'instance:analytics:show' => ServingNode::InstanceOwning,
        'instance:analytics:stats' => ServingNode::InstanceOwning,
        'instance:clone' => ServingNode::CandidateClone,
        'instance:create' => ServingNode::InstanceOwning,
        'instance:database:add' => ServingNode::EnvironmentInstanceOwning,
        'instance:database:remove' => ServingNode::EnvironmentInstanceOwning,
        'instance:dependencies:scan' => ServingNode::InstanceOwning,
        'instance:dependencies:show' => ServingNode::InstanceOwning,
        'instance:dependencies:update' => ServingNode::InstanceOwning,
        'instance:deploy' => ServingNode::InstanceOwning,
        'instance:deploy-step:create' => ServingNode::InstanceOwning,
        'instance:deploy-step:destroy' => ServingNode::InstanceOwning,
        'instance:deploy-step:list' => ServingNode::InstanceOwning,
        'instance:deploy-step:update' => ServingNode::InstanceOwning,
        'instance:deployment:list' => ServingNode::InstanceOwning,
        'instance:deployment:show' => ServingNode::DeploymentOwning,
        'instance:destroy' => ServingNode::InstanceOwning,
        'instance:list' => ServingNode::Collection,
        'instance:logs' => ServingNode::InstanceOwning,
        'instance:queue' => ServingNode::InstanceOwning,
        'instance:register' => ServingNode::Caller,
        'instance:release:list' => ServingNode::InstanceOwning,
        'instance:resolve' => ServingNode::Collection,
        'instance:resolve-directory' => ServingNode::Collection,
        'instance:rollback' => ServingNode::InstanceOwning,
        'instance:setup' => ServingNode::InstanceOwning,
        'instance:setup-step:create' => ServingNode::AppOwning,
        'instance:setup-step:destroy' => ServingNode::AppOwning,
        'instance:setup-step:list' => ServingNode::AppOwning,
        'instance:setup-step:update' => ServingNode::AppOwning,
        'instance:show' => ServingNode::InstanceOwning,
        'instance:teardown-step:create' => ServingNode::AppOwning,
        'instance:teardown-step:destroy' => ServingNode::AppOwning,
        'instance:teardown-step:list' => ServingNode::AppOwning,
        'instance:teardown-step:update' => ServingNode::AppOwning,
        'instance:transfer' => ServingNode::InstanceTransfer,
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
        'node:excluded-project:add' => ServingNode::Target,
        'node:excluded-project:list' => ServingNode::Target,
        'node:excluded-project:remove' => ServingNode::Target,
        'node:list' => ServingNode::Collection,
        'node:metrics' => ServingNode::Target,
        'node:remove' => ServingNode::Target,
        'node:rename' => ServingNode::Target,
        'node:role:add' => ServingNode::RoleMutation,
        'node:role:list' => ServingNode::Target,
        'node:role:relocate' => ServingNode::Gateway,
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
        'project:create' => ServingNode::Gateway,
        'project:destroy' => ServingNode::AppOwning,
        'project:excluded-node:add' => ServingNode::AppOwning,
        'project:excluded-node:list' => ServingNode::AppOwning,
        'project:excluded-node:remove' => ServingNode::AppOwning,
        'project:list' => ServingNode::Collection,
        'project:process-definition:create' => ServingNode::AppOwning,
        'project:process-definition:destroy' => ServingNode::AppOwning,
        'project:process-definition:list' => ServingNode::AppOwning,
        'project:process-definition:show' => ServingNode::AppOwning,
        'project:process-definition:update' => ServingNode::AppOwning,
        'project:schedule-definition:create' => ServingNode::AppOwning,
        'project:schedule-definition:destroy' => ServingNode::AppOwning,
        'project:schedule-definition:list' => ServingNode::AppOwning,
        'project:schedule-definition:show' => ServingNode::AppOwning,
        'project:schedule-definition:update' => ServingNode::AppOwning,
        'project:show' => ServingNode::AppOwning,
        'project:update' => ServingNode::AppOwning,
        'proxycli:disable' => ServingNode::Gateway,
        'proxycli:enable' => ServingNode::Gateway,
        'proxycli:list' => ServingNode::Gateway,
        'proxycli:show' => ServingNode::Gateway,
        'proxycli:status' => ServingNode::Gateway,
        'proxycli:update' => ServingNode::Gateway,
        'realtime:auth' => ServingNode::Gateway,
        'realtime:show' => ServingNode::Gateway,
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
        'tasks:add' => ServingNode::Gateway,
        'tasks:agent-stream' => ServingNode::Gateway,
        'tasks:agents' => ServingNode::Gateway,
        'tasks:cancel' => ServingNode::Gateway,
        'tasks:comment:create' => ServingNode::Gateway,
        'tasks:comment:list' => ServingNode::Gateway,
        'tasks:complete' => ServingNode::Gateway,
        'tasks:create' => ServingNode::Gateway,
        'tasks:disable' => ServingNode::Gateway,
        'tasks:enable' => ServingNode::Gateway,
        'tasks:list' => ServingNode::Collection,
        'tasks:show' => ServingNode::Collection,
        'tasks:status' => ServingNode::Gateway,
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
