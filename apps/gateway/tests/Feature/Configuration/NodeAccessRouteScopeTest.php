<?php

declare(strict_types=1);

use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use App\Http\Middleware\RequireNodeAgentSecret;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Route;

it('declares node access scope on every active-peer API route', function (): void {
    // The agent routes need the agent secret instead. Any active peer reads the desired fleet state (ADR 0202),
    // because every managed Node updates itself from it, with or without access to the Gateway.
    $agentRoutes = ['agent:realtime', 'agent:realtime:auth', 'agent:workspaces', 'agent:log-streams', 'gateway:desired-fleet-state'];
    $protectedRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(static fn (IlluminateRoute $route): bool => str_starts_with($route->uri(), 'api/v1/'))
        ->filter(
            static fn (IlluminateRoute $route): bool => in_array(
                RequireActiveWireGuardPeer::class,
                $route->gatherMiddleware(),
                strict: true,
            ) && ! in_array($route->getName(), $agentRoutes, strict: true),
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
        'annotation:create' => ServingNode::InstanceOwning,
        'annotation:events' => ServingNode::InstanceOwning,
        'annotation:list' => ServingNode::InstanceOwning,
        'annotation:retry' => ServingNode::InstanceOwning,
        'annotation:update' => ServingNode::InstanceOwning,
        'cluster:create' => ServingNode::Gateway,
        'cluster:destroy' => ServingNode::ClusterOwning,
        'cluster:list' => ServingNode::Collection,
        'cluster:node:add' => ServingNode::Target,
        'cluster:node:remove' => ServingNode::Target,
        'cluster:router:set' => ServingNode::Target,
        'cluster:router:unset' => ServingNode::ClusterOwning,
        'cluster:show' => ServingNode::ClusterOwning,
        'cluster:update' => ServingNode::ClusterOwning,
        'compute:github-token' => ServingNode::Caller,
        'database:create' => ServingNode::Gateway,
        'database:describe' => ServingNode::Gateway,
        'database:destroy' => ServingNode::Gateway,
        'database:list' => ServingNode::Gateway,
        'database:query' => ServingNode::Gateway,
        'database:schema' => ServingNode::Gateway,
        'database:server:create' => ServingNode::Gateway,
        'database:server:destroy' => ServingNode::Gateway,
        'database:server:list' => ServingNode::Gateway,
        'database:server:show' => ServingNode::Gateway,
        'database:show' => ServingNode::Gateway,
        'database:tables' => ServingNode::Gateway,
        'database:update' => ServingNode::Gateway,
        'database:user:create' => ServingNode::Gateway,
        'database:user:list' => ServingNode::Gateway,
        'doctor' => ServingNode::Collection,
        'env:import' => ServingNode::EnvironmentInstanceOwning,
        'env:sync' => ServingNode::EnvironmentInstanceOwning,
        'env:update' => ServingNode::EnvironmentInstanceOwning,
        'extension:disable' => ServingNode::Gateway,
        'extension:enable' => ServingNode::Gateway,
        'extension:list' => ServingNode::Gateway,
        'firewall:allow' => ServingNode::Target,
        'firewall:deny' => ServingNode::Target,
        'firewall:fleet:list' => ServingNode::Collection,
        'firewall:list' => ServingNode::Target,
        'firewall:live:list' => ServingNode::Target,
        'firewall:managed:list' => ServingNode::Target,
        'firewall:remove' => ServingNode::Target,
        'gateway:release:auto:disable' => ServingNode::Gateway,
        'gateway:release:auto:enable' => ServingNode::Gateway,
        'gateway:release:auto:resume' => ServingNode::Gateway,
        'gateway:release:auto:status' => ServingNode::Gateway,
        'gateway:release:deploy' => ServingNode::Gateway,
        'gateway:release:list' => ServingNode::Gateway,
        'gateway:release:rollback' => ServingNode::Gateway,
        'gateway:release:show' => ServingNode::Gateway,
        'github:app:callback' => ServingNode::Gateway,
        'github:app:destroy' => ServingNode::Gateway,
        'github:app:install' => ServingNode::Gateway,
        'github:app:register' => ServingNode::Gateway,
        'github:app:show' => ServingNode::Gateway,
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
        'instance:log-stream:create' => ServingNode::InstanceOwning,
        'instance:log-stream:destroy' => ServingNode::InstanceOwning,
        'instance:log-stream:renew' => ServingNode::InstanceOwning,
        'instance:logs' => ServingNode::InstanceOwning,
        'instance:queue' => ServingNode::InstanceOwning,
        'instance:register' => ServingNode::Caller,
        'instance:release:list' => ServingNode::InstanceOwning,
        'instance:rename' => ServingNode::InstanceOwning,
        'instance:resolve' => ServingNode::Collection,
        'instance:resolve-directory' => ServingNode::Collection,
        'instance:rollback' => ServingNode::InstanceOwning,
        'instance:setup' => ServingNode::InstanceOwning,
        'instance:setup-step:create' => ServingNode::ProjectOwning,
        'instance:setup-step:destroy' => ServingNode::ProjectOwning,
        'instance:setup-step:list' => ServingNode::ProjectOwning,
        'instance:setup-step:update' => ServingNode::ProjectOwning,
        'instance:show' => ServingNode::InstanceOwning,
        'instance:teardown-step:create' => ServingNode::ProjectOwning,
        'instance:teardown-step:destroy' => ServingNode::ProjectOwning,
        'instance:teardown-step:list' => ServingNode::ProjectOwning,
        'instance:teardown-step:update' => ServingNode::ProjectOwning,
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
        'process:create' => ServingNode::ProcessOwning,
        'process:destroy' => ServingNode::ProcessOwning,
        'process:list' => ServingNode::ProcessOwning,
        'process:log-stream:create' => ServingNode::ProcessOwning,
        'process:log-stream:destroy' => ServingNode::ProcessOwning,
        'process:log-stream:renew' => ServingNode::ProcessOwning,
        'process:logs' => ServingNode::ProcessOwning,
        'process:restart' => ServingNode::ProcessOwning,
        'process:start' => ServingNode::ProcessOwning,
        'process:stop' => ServingNode::ProcessOwning,
        'project:create' => ServingNode::Gateway,
        'project:destroy' => ServingNode::ProjectOwning,
        'project:dev-deploy-step:create' => ServingNode::ProjectOwning,
        'project:dev-deploy-step:destroy' => ServingNode::ProjectOwning,
        'project:dev-deploy-step:list' => ServingNode::ProjectOwning,
        'project:dev-deploy-step:update' => ServingNode::ProjectOwning,
        'project:document-storage:show' => ServingNode::Gateway,
        'project:document-storage:update' => ServingNode::Gateway,
        'project:document:archive' => ServingNode::ProjectOwning,
        'project:document:create' => ServingNode::ProjectOwning,
        'project:document:destroy' => ServingNode::ProjectOwning,
        'project:document:download' => ServingNode::ProjectOwning,
        'project:document:list' => ServingNode::ProjectOwning,
        'project:document:read' => ServingNode::ProjectOwning,
        'project:document:restore' => ServingNode::ProjectOwning,
        'project:document:restore-version' => ServingNode::ProjectOwning,
        'project:document:search' => ServingNode::ProjectOwning,
        'project:document:show' => ServingNode::ProjectOwning,
        'project:document:update' => ServingNode::ProjectOwning,
        'project:document:version:list' => ServingNode::ProjectOwning,
        'project:document:write' => ServingNode::ProjectOwning,
        'project:excluded-node:add' => ServingNode::ProjectOwning,
        'project:excluded-node:list' => ServingNode::ProjectOwning,
        'project:excluded-node:remove' => ServingNode::ProjectOwning,
        'project:list' => ServingNode::Collection,
        'project:process-definition:create' => ServingNode::ProjectOwning,
        'project:process-definition:destroy' => ServingNode::ProjectOwning,
        'project:process-definition:list' => ServingNode::ProjectOwning,
        'project:process-definition:show' => ServingNode::ProjectOwning,
        'project:process-definition:update' => ServingNode::ProjectOwning,
        'project:schedule-definition:create' => ServingNode::ProjectOwning,
        'project:schedule-definition:destroy' => ServingNode::ProjectOwning,
        'project:schedule-definition:list' => ServingNode::ProjectOwning,
        'project:schedule-definition:show' => ServingNode::ProjectOwning,
        'project:schedule-definition:update' => ServingNode::ProjectOwning,
        'project:show' => ServingNode::ProjectOwning,
        'project:update' => ServingNode::ProjectOwning,
        'proxycli:list' => ServingNode::Gateway,
        'proxycli:models' => ServingNode::Gateway,
        'proxycli:setup' => ServingNode::Gateway,
        'proxycli:show' => ServingNode::Gateway,
        'proxycli:status' => ServingNode::Gateway,
        'proxycli:teardown' => ServingNode::Gateway,
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
        'runtime-activation:app-instance' => ServingNode::InstanceHost,
        'schedule:complete' => ServingNode::ScheduleHost,
        'schedule:create' => ServingNode::ScheduleOwning,
        'schedule:destroy' => ServingNode::ScheduleOwning,
        'schedule:enable' => ServingNode::ScheduleOwning,
        'schedule:list' => ServingNode::Collection,
        'schedule:logs' => ServingNode::ScheduleOwning,
        'schedule:run' => ServingNode::ScheduleOwning,
        'schedule:show' => ServingNode::ScheduleOwning,
        'tasks:agent-stream' => ServingNode::Gateway,
        'tasks:agents' => ServingNode::Gateway,
        'tasks:cancel' => ServingNode::Gateway,
        'tasks:check:cancel' => ServingNode::Gateway,
        'tasks:comment:create' => ServingNode::Gateway,
        'tasks:comment:list' => ServingNode::Gateway,
        'tasks:complete' => ServingNode::Gateway,
        'tasks:create' => ServingNode::Gateway,
        'tasks:definition:create' => ServingNode::Gateway,
        'tasks:definition:destroy' => ServingNode::Gateway,
        'tasks:definition:list' => ServingNode::Collection,
        'tasks:definition:show' => ServingNode::Collection,
        'tasks:definition:update' => ServingNode::Gateway,
        'tasks:list' => ServingNode::Collection,
        'tasks:question:list' => ServingNode::Collection,
        'tasks:show' => ServingNode::Collection,
        'tasks:status' => ServingNode::Gateway,
        'tasks:subtask:cancel' => ServingNode::Gateway,
        'tasks:subtask:create' => ServingNode::TaskGroupOwning,
        'tasks:subtask:destroy' => ServingNode::TaskGroupOwning,
        'tasks:subtask:update' => ServingNode::TaskGroupOwning,
        'tasks:update' => ServingNode::TaskGroupOwning,
        'tool:adopt' => ServingNode::ToolOwning,
        'tool:install' => ServingNode::ToolOwning,
        'tool:list' => ServingNode::ToolOwning,
        'tool:manager:list' => ServingNode::ToolOwning,
        'tool:remove' => ServingNode::ToolOwning,
        'tool:scan' => ServingNode::ToolOwning,
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
        'tool:adopt' => ['POST', 'api/v1/tools/adopt'],
        'tool:install' => ['POST', 'api/v1/tools'],
        'tool:list' => ['GET', 'api/v1/tools'],
        'tool:manager:list' => ['GET', 'api/v1/tool-managers'],
        'tool:remove' => ['DELETE', 'api/v1/tools/{tool}'],
        'tool:scan' => ['GET', 'api/v1/tool-inventory'],
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

        if (in_array($route->getName(), ['agent:realtime', 'agent:realtime:auth', 'agent:workspaces', 'agent:log-streams'], strict: true)) {
            expect($middleware)
                ->toContain(RequireActiveWireGuardPeer::class)
                ->toContain(RequireNodeAgentSecret::class)
                ->not->toContain(RequireNodeAccess::class);

            continue;
        }

        if ($route->getName() === 'gateway:desired-fleet-state') {
            expect($middleware)
                ->toContain(RequireActiveWireGuardPeer::class)
                ->not->toContain(RequireNodeAccess::class);

            continue;
        }

        // Every agent endpoint needs the agent's secret as well as the Node's address (ADR 0155).
        expect(str_starts_with($route->uri(), 'api/v1/agent/'))->toBeFalse();

        expect($middleware)
            ->toContain(RequireActiveWireGuardPeer::class)
            ->toContain(RequireNodeAccess::class);
    }

    expect($apiRoutes->map(static fn (IlluminateRoute $route): ?string => $route->getName())->all())
        ->toContain('gateway:status', 'gateway:trust');
});
