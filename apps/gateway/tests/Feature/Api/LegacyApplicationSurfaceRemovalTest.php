<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Http\Controllers\Api\AppInstancesController;
use App\Http\Controllers\Api\InstancesController;
use App\Http\Controllers\Api\WorkspacesController;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

describe('legacy application surface removal', function (): void {
    beforeEach(function (): void {
        $this->node = Node::query()->create([
            'name' => 'app-dev',
            'tld' => 'app-dev.orbit',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.3',
        ]);
        $this->node
            ->roles()
            ->create([
                'role' => RoleName::AppDev,
                'status' => LifecycleStatus::Active,
            ]);
        $this->node->accessibleNodes()->attach($this->node);
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.3']);
        $this->orbitApp = OrbitApp::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
            'repository_url' => 'git@github.com:acme/site.git',
            'default_branch' => 'main',
            'root' => 'public',
        ]);
        $this->appInstance = AppInstance::query()->create([
            'app_id' => $this->orbitApp->id,
            'node_id' => $this->node->id,
            'name' => 'default',
            'checkout_path' => '/srv/orbit/apps/acme/default',
            'source_layout' => 'worktree',
            'status' => AppInstanceState::Active,
        ]);
    });

    it('discovers no Workspace or leftover Instance operations and keeps AppInstance instance verbs', function (): void {
        $named = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn (IlluminateRoute $route): bool => is_string($route->getName()))
            ->mapWithKeys(static fn (IlluminateRoute $route): array => [
                $route->getName() => [$route->methods()[0], $route->uri(), $route->getControllerClass()],
            ])
            ->all();
        ksort($named);

        expect($named)
            ->not
            ->toHaveKeys([
                'workspace:list',
                'workspace:show',
                'workspace:new',
                'workspace:remove',
                'workspace:php',
                'instance:php',
                'instance:convert',
                'workspace:convert',
            ])
            ->and($named['instance:list'] ?? null)
            ->toBe(['GET', 'api/v1/instances', AppInstancesController::class])
            ->and($named['instance:show'] ?? null)
            ->toBe(['GET', 'api/v1/instances/{instance}', AppInstancesController::class])
            ->and($named['instance:create'] ?? null)
            ->toBe(['POST', 'api/v1/instances', AppInstancesController::class])
            ->and($named['instance:destroy'] ?? null)
            ->toBe(['DELETE', 'api/v1/instances/{instance}', AppInstancesController::class])
            ->and(class_exists(InstancesController::class))
            ->toBeFalse()
            ->and(class_exists(WorkspacesController::class))
            ->toBeFalse()
            ->and(class_exists('App\\Models\\Instance'))
            ->toBeFalse()
            ->and(class_exists('App\\Models\\Workspace'))
            ->toBeFalse();

        foreach ($named as [$method, $uri, $controller]) {
            expect($controller)
                ->not
                ->toBe(InstancesController::class)
                ->and($controller)
                ->not
                ->toBe(WorkspacesController::class)
                ->and($uri)
                ->not
                ->toContain('convert')
                ->and($uri)
                ->not
                ->toContain('migrate');
            expect($method)->toBeString();
        }
    });

    it('rejects retired Workspace and leftover Instance inputs without mutation', function (string $method, string $uri, array $payload): void {
        $appInstanceBefore = $this->appInstance->only(['id', 'name', 'checkout_path', 'status', 'source_layout']);

        $this
            ->json($method, $uri, $payload)
            ->assertNotFound();

        expect($this->appInstance->refresh()->only(['id', 'name', 'checkout_path', 'status', 'source_layout']))
            ->toBe($appInstanceBefore)
            ->and(AppInstance::query()->count())
            ->toBe(1)
            ->and(Schema::hasTable('instances'))
            ->toBeFalse()
            ->and(Schema::hasTable('workspaces'))
            ->toBeFalse();
    })->with([
        'list workspaces' => ['GET', '/api/v1/workspaces', []],
        'show workspace' => ['GET', '/api/v1/workspaces/1', []],
        'create workspace' => ['POST', '/api/v1/workspaces', [
            'instance_id' => 1,
            'name' => 'feature-one',
        ]],
        'remove workspace' => ['DELETE', '/api/v1/workspaces/1', []],
        'update workspace php' => ['PATCH', '/api/v1/workspaces/1/php', [
            'php_version' => '8.4',
        ]],
        'legacy instance php' => ['PATCH', '/api/v1/instances/1/php', [
            'php_version' => '8.4',
        ]],
        'legacy conversion' => ['POST', '/api/v1/instances/1/convert', []],
        'workspace conversion' => ['POST', '/api/v1/workspaces/1/convert', []],
    ]);

    it('keeps supported AppInstance request and response values on instance verbs', function (): void {
        $this
            ->getJson('/api/v1/instances')
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->appInstance->id)
            ->assertJsonPath('data.0.app_id', $this->orbitApp->id)
            ->assertJsonPath('data.0.node_id', $this->node->id)
            ->assertJsonPath('data.0.name', 'default')
            ->assertJsonPath('data.0.source_layout', 'worktree')
            ->assertJsonMissingPath('data.0.certificate_mode')
            ->assertJsonMissingPath('data.0.document_root')
            ->assertJsonMissingPath('data.0.php_version')
            ->assertJsonMissingPath('data.0.instance_id');

        $this
            ->getJson("/api/v1/instances/{$this->appInstance->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $this->appInstance->id)
            ->assertJsonPath('data.app_id', $this->orbitApp->id)
            ->assertJsonPath('data.node_id', $this->node->id)
            ->assertJsonPath('data.name', 'default')
            ->assertJsonPath('data.source_layout', 'worktree')
            ->assertJsonMissingPath('data.certificate_mode')
            ->assertJsonMissingPath('data.document_root')
            ->assertJsonMissingPath('data.php_version')
            ->assertJsonMissingPath('data.instance_id');
    });
});
