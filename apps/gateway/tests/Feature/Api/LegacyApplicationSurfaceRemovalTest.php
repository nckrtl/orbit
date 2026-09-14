<?php

declare(strict_types=1);

use App\Domain\AppDev\AppDevRuntimeConverger;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Instances\CertificateMode;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Http\Controllers\Api\AppInstancesController;
use App\Http\Controllers\Api\InstancesController;
use App\Http\Controllers\Api\WorkspacesController;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Workspace;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Route;

describe('legacy application surface removal', function (): void {
    beforeEach(function (): void {
        $this->runtime = new class implements AppDevRuntimeConverger
        {
            /** @var list<string> */
            public array $calls = [];

            public function convergeInstance(Instance $instance): void
            {
                $this->calls[] = "instance:{$instance->id}";
            }

            public function removeInstance(Instance $instance): void
            {
                $this->calls[] = "instance-remove:{$instance->id}";
            }

            public function unpublishInstance(Instance $instance): void {}

            public function convergeWorkspace(Workspace $workspace): void
            {
                $this->calls[] = "workspace:{$workspace->id}";

                throw new RuntimeConvergenceException(
                    step: 'git-worktree',
                    errorCode: 'workspace.worktree_failed',
                    message: 'Worktree failed.',
                );
            }

            public function removeWorkspace(Workspace $workspace): void
            {
                $this->calls[] = "workspace-remove:{$workspace->id}";
            }

            public function unpublishWorkspace(Workspace $workspace): void {}
        };
        app()->instance(AppDevRuntimeConverger::class, $this->runtime);

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
        $this->legacy = Instance::query()->create([
            'app_id' => $this->orbitApp->id,
            'node_id' => $this->node->id,
            'name' => 'legacy',
            'environment' => 'development',
            'checkout_path' => '/srv/orbit/legacy/acme',
            'domain' => 'legacy.example.test',
            'certificate_mode' => CertificateMode::OrbitCa,
            'status' => LifecycleStatus::Active,
        ]);
        $this->workspace = Workspace::query()->create([
            'instance_id' => $this->legacy->id,
            'name' => 'workspace',
            'branch' => 'workspace',
            'checkout_path' => '/srv/orbit/workspaces/acme/workspace',
            'domain' => 'workspace.example.test',
            'status' => LifecycleStatus::Active,
        ]);
        $this->appInstance = AppInstance::query()->create([
            'app_id' => $this->orbitApp->id,
            'node_id' => $this->node->id,
            'name' => 'default',
            'checkout_path' => '/srv/orbit/apps/acme/default',
            'status' => AppInstanceState::Active,
        ]);
    });

    it('discovers no Workspace or legacy Instance operations and keeps AppInstance instance verbs', function (): void {
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
            ->toBe(['DELETE', 'api/v1/instances/{instance}', AppInstancesController::class]);

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

    it('rejects retired Workspace and legacy Instance inputs without mutation', function (string $method, string $uri, array $payload): void {
        $legacyBefore = $this->legacy->only(['id', 'name', 'checkout_path', 'domain', 'status']);
        $workspaceBefore = $this->workspace->only(['id', 'instance_id', 'name', 'checkout_path', 'status']);
        $appInstanceBefore = $this->appInstance->only(['id', 'name', 'checkout_path', 'status']);
        $resolvedUri = str_replace(
            ['{workspace}', '{legacy}'],
            [(string) $this->workspace->id, (string) $this->legacy->id],
            $uri,
        );
        $resolvedPayload = $payload === []
            ? []
            : [
                ...$payload,
                ...isset($payload['instance_id']) ? ['instance_id' => $this->legacy->id] : [],
            ];

        $this
            ->json($method, $resolvedUri, $resolvedPayload)
            ->assertNotFound();

        expect($this->legacy->refresh()->only(['id', 'name', 'checkout_path', 'domain', 'status']))
            ->toBe($legacyBefore)
            ->and($this->workspace->refresh()->only(['id', 'instance_id', 'name', 'checkout_path', 'status']))
            ->toBe($workspaceBefore)
            ->and($this->appInstance->refresh()->only(['id', 'name', 'checkout_path', 'status']))
            ->toBe($appInstanceBefore)
            ->and(Instance::query()->count())
            ->toBe(1)
            ->and(Workspace::query()->count())
            ->toBe(1)
            ->and(AppInstance::query()->count())
            ->toBe(1)
            ->and($this->runtime->calls)
            ->toBeEmpty();
    })->with([
        'list workspaces' => ['GET', '/api/v1/workspaces', []],
        'show workspace' => ['GET', '/api/v1/workspaces/{workspace}', []],
        'create workspace' => ['POST', '/api/v1/workspaces', [
            'instance_id' => 0,
            'name' => 'feature-one',
        ]],
        'remove workspace' => ['DELETE', '/api/v1/workspaces/{workspace}', []],
        'update workspace php' => ['PATCH', '/api/v1/workspaces/{workspace}/php', [
            'php_version' => '8.4',
        ]],
        'legacy instance php' => ['PATCH', '/api/v1/instances/{legacy}/php', [
            'php_version' => '8.4',
        ]],
        'legacy conversion' => ['POST', '/api/v1/instances/{legacy}/convert', []],
        'workspace conversion' => ['POST', '/api/v1/workspaces/{workspace}/convert', []],
    ]);

    it('keeps supported AppInstance request and response values on instance verbs', function (): void {
        $this
            ->getJson('/api/v1/instances')
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->appInstance->id)
            ->assertJsonPath('data.0.app_id', $this->orbitApp->id)
            ->assertJsonPath('data.0.node_id', $this->node->id)
            ->assertJsonPath('data.0.name', 'default')
            ->assertJsonPath('data.0.source_layout', 'checkout')
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
            ->assertJsonMissingPath('data.certificate_mode')
            ->assertJsonMissingPath('data.document_root')
            ->assertJsonMissingPath('data.php_version')
            ->assertJsonMissingPath('data.instance_id');

        expect($this->legacy->refresh()->name)
            ->toBe('legacy')
            ->and($this->workspace->refresh()->name)
            ->toBe('workspace');
    });
});
