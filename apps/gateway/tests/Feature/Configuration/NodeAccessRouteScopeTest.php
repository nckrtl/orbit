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
        $actualScopes[$route->getName()] = $attribute->newInstance()->servingNode;
    }

    ksort($actualScopes);

    $expectedScopes = [
        'activity:list' => ServingNode::Gateway,
        'activity:show' => ServingNode::Gateway,
        'app:list' => ServingNode::Collection,
        'app:new' => ServingNode::Gateway,
        'app:remove' => ServingNode::AppOwning,
        'app:show' => ServingNode::AppOwning,
        'doctor:run' => ServingNode::Collection,
        'firewall:allow' => ServingNode::Target,
        'firewall:deny' => ServingNode::Target,
        'firewall:list' => ServingNode::Target,
        'firewall:remove' => ServingNode::Target,
        'instance:list' => ServingNode::Collection,
        'instance:new' => ServingNode::InstanceOwning,
        'instance:php' => ServingNode::InstanceOwning,
        'instance:remove' => ServingNode::InstanceOwning,
        'instance:show' => ServingNode::InstanceOwning,
        'metrics:credentials' => ServingNode::Gateway,
        'metrics:credentials:reset' => ServingNode::Gateway,
        'metrics:enable' => ServingNode::Gateway,
        'metrics:exporter:disable' => ServingNode::Gateway,
        'metrics:exporter:enable' => ServingNode::Gateway,
        'metrics:remove' => ServingNode::Gateway,
        'metrics:status' => ServingNode::Gateway,
        'node:access:add' => ServingNode::Gateway,
        'node:access:remove' => ServingNode::Gateway,
        'node:list' => ServingNode::Collection,
        'node:provision' => ServingNode::Gateway,
        'node:remove' => ServingNode::Target,
        'node:role:add' => ServingNode::RoleMutation,
        'node:role:list' => ServingNode::Target,
        'node:role:remove' => ServingNode::RoleMutation,
        'node:show' => ServingNode::Target,
        'process:add' => ServingNode::ProcessOwning,
        'process:list' => ServingNode::ProcessOwning,
        'process:logs' => ServingNode::ProcessOwning,
        'process:remove' => ServingNode::ProcessOwning,
        'process:restart' => ServingNode::ProcessOwning,
        'process:start' => ServingNode::ProcessOwning,
        'process:stop' => ServingNode::ProcessOwning,
        'tool:install' => ServingNode::ToolOwning,
        'tool:list' => ServingNode::ToolOwning,
        'tool:manager:list' => ServingNode::ToolOwning,
        'tool:remove' => ServingNode::ToolOwning,
        'tool:show' => ServingNode::ToolOwning,
        'tool:update' => ServingNode::ToolOwning,
        'workspace:list' => ServingNode::Collection,
        'workspace:new' => ServingNode::WorkspaceOwning,
        'workspace:php' => ServingNode::WorkspaceOwning,
        'workspace:remove' => ServingNode::WorkspaceOwning,
        'workspace:show' => ServingNode::WorkspaceOwning,
    ];

    expect($actualScopes)->toBe($expectedScopes);
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
