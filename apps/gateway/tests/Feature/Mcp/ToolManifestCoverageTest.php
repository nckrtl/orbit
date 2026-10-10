<?php

declare(strict_types=1);

use App\Http\Mcp\ToolManifest;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * The MCP catalogue is the API catalogue. A route added without regenerating the manifest
 * (`bin/docs-openapi`, then `bin/mcp-tools`) fails here, so an agent never trails the CLI.
 */
describe('MCP tool manifest', function (): void {
    // Machine callbacks that bin/mcp-tools leaves out on purpose; keep the two lists identical.
    $excluded = [
        'annotation:events',
        'tasks:agent-stream',
        'realtime:auth',
        'agent:realtime',
        'agent:realtime:auth',
        'agent:workspaces',
        'agent:log-streams',
        'instance:log-stream:create',
        'instance:log-stream:renew',
        'instance:log-stream:destroy',
        'process:log-stream:create',
        'process:log-stream:renew',
        'process:log-stream:destroy',
        'metrics:grafana:authorize',
        'runtime-activation:app-instance',
        'schedule:complete',
        'github:app:register',
        'github:app:callback',
    ];

    $signature = static fn (string $method, string $path): string => $method.' '.preg_replace('/\{[^}]+\}/', '{}', '/'.ltrim($path, '/'));

    it('offers a tool for every API operation', function () use ($excluded, $signature): void {
        $tools = array_map(
            static fn ($definition): string => $signature($definition->method, $definition->path),
            ToolManifest::default()->definitions(),
        );

        $missing = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            assert($route instanceof RoutingRoute);

            if (! str_starts_with($route->uri(), 'api/v1/') || in_array($route->getName(), $excluded, true)) {
                continue;
            }

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                if (! in_array($signature($method, $route->uri()), $tools, true)) {
                    $missing[] = "{$method} {$route->uri()} ({$route->getName()})";
                }
            }
        }

        expect($missing)->toBe([]);
    });

    it('points every tool at a registered route', function () use ($signature): void {
        $routes = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                $routes[] = $signature($method, $route->uri());
            }
        }

        $orphans = array_values(array_filter(
            ToolManifest::default()->definitions(),
            static fn ($definition): bool => ! in_array($signature($definition->method, $definition->path), $routes, true),
        ));

        expect(array_map(static fn ($definition): string => $definition->name, $orphans))->toBe([]);
    });

    it('types node-role-add converge_existing as a boolean', function (): void {
        $tool = collect(ToolManifest::default()->definitions())
            ->first(static fn ($definition): bool => $definition->name === 'node-role-add');

        expect($tool)->not->toBeNull()
            ->and($tool?->inputSchema['properties']['converge_existing']['type'] ?? null)
            ->toBe('boolean');
    });

    it('gives every tool a unique name an MCP client accepts', function (): void {
        $names = array_map(static fn ($definition): string => $definition->name, ToolManifest::default()->definitions());

        expect($names)->toBe(array_values(array_unique($names)));

        foreach ($names as $name) {
            expect($name)->toMatch('/\A[a-z0-9_-]{1,64}\z/');
        }
    });
});
