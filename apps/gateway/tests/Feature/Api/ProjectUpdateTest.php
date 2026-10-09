<?php

declare(strict_types=1);

use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Activity;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Support\Str;
use Tests\Support\Orb101ProjectUpdateFixture;

beforeEach(function (): void {
    $this->operator = $this->markAsGateway(Node::query()->create([
        'name' => 'operator',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    $this->fixture = Orb101ProjectUpdateFixture::bind($this);
});

describe('app updates', function (): void {
    it('sets and clears the task check as a visible Project setting', function (): void {
        $command = 'vp run check --filter=api';

        $this->patchJson('/api/v1/projects/'.$this->fixture->project->id, [
            'task_check' => $command,
        ])
            ->assertOk()
            ->assertJsonPath('data.task_check', $command);

        expect($this->fixture->project->refresh()->taskCheckCommand())
            ->toBe($command)
            ->and(Activity::query()->latest('id')->first()?->properties['input']['task_check'] ?? null)
            ->toBe($command);

        $this->patchJson('/api/v1/projects/'.$this->fixture->project->id, [
            'task_check' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.task_check', null);

        expect($this->fixture->project->refresh()->taskCheckCommand())->toBeNull();
    });

    it('records the task check in activity on the compatibility path', function (): void {
        $this->patchJson('/api/v1/projects/'.$this->fixture->project->id, [
            'task_check' => 'composer test',
        ])
            ->assertOk()
            ->assertJsonPath('data.task_check', 'composer test');

        expect(Activity::query()->latest('id')->first()?->properties['input'] ?? null)
            ->toBe(['task_check' => 'composer test']);

        $this->patchJson('/api/v1/projects/'.$this->fixture->project->id, [
            'task_check' => null,
        ])->assertOk();

        expect(Activity::query()->latest('id')->first()?->properties['input'] ?? null)
            ->toBe(['task_check' => null]);
    });

    it('stores the task check inside the update operation lock', function (): void {
        $lock = new class($this->fixture->project->id) implements InstanceEnvironmentOperationLock
        {
            /** @var list<array{ids: list<int>, stored: string|null}> */
            public array $runs = [];

            public function __construct(private readonly int $projectId) {}

            public function run(array $instanceIds, Closure $operation): mixed
            {
                $result = $operation();
                $this->runs[] = ['ids' => $instanceIds, 'stored' => Project::query()->findOrFail($this->projectId)->task_check];

                return $result;
            }
        };
        $this->app->instance(InstanceEnvironmentOperationLock::class, $lock);

        $this->patchJson('/api/v1/projects/'.$this->fixture->project->id, [
            'task_check' => 'composer test',
        ])->assertOk();

        expect($lock->runs)->toHaveCount(1)
            ->and($lock->runs[0]['ids'])->toBe([$this->fixture->defaultInstance->id])
            ->and($lock->runs[0]['stored'])->toBe('composer test');
    });

    it('refuses an apps update while the Project has Instances', function (): void {
        $before = $this->fixture->project->refresh()->apps;

        $this
            ->patchJson('/api/v1/projects/'.$this->fixture->project->id, [
                'apps' => [...$before, ['name' => 'docs', 'path' => 'docs', 'web_root' => 'public', 'type' => 'laravel-app']],
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'project.apps_locked_by_instances')
            ->assertJsonPath('error.message', "Project [{$this->fixture->project->slug}] has Instances, so its apps cannot change. Remove its Instances, change the apps, then recreate the Instances.");

        expect($this->fixture->project->refresh()->apps)->toBe($before);
    });

    it('replaces the apps of a Project without Instances', function (): void {
        $project = Project::query()->create([
            'name' => 'Empty', 'slug' => 'empty', 'repository_url' => 'https://github.com/acme/empty.git',
            'default_branch' => 'main', 'apps' => fixture_apps('public'),
        ]);
        $apps = [
            ['name' => 'web', 'path' => 'apps/site', 'web_root' => 'public', 'type' => 'laravel-app'],
            ['name' => 'docs', 'path' => 'apps/docs', 'web_root' => 'public', 'type' => 'laravel-app'],
        ];

        $this
            ->patchJson('/api/v1/projects/'.$project->id, ['apps' => $apps])
            ->assertOk()
            ->assertJsonPath('data.apps', [$apps[1], $apps[0]]);

        expect($project->refresh()->apps)->toBe([$apps[1], $apps[0]])
            ->and($project->updates()->exists())->toBeFalse();
    });

    it('keeps an app that a Process definition still names', function (): void {
        $project = Project::query()->create([
            'name' => 'Named', 'slug' => 'named', 'repository_url' => 'https://github.com/acme/named.git', 'default_branch' => 'main',
            'apps' => [
                ['name' => 'docs', 'path' => 'apps/docs', 'web_root' => 'public', 'type' => 'laravel-app'],
                ['name' => 'web', 'path' => 'apps/site', 'web_root' => 'public', 'type' => 'laravel-app'],
            ],
        ]);
        $project->processDefinitions()->create(['app' => 'docs', 'name' => 'queue', 'environments' => ['development'], 'spec' => ['runtime' => 'systemd', 'command' => ['/usr/bin/php', 'artisan', 'queue:work']]]);

        $this
            ->patchJson('/api/v1/projects/'.$project->id, ['apps' => [['name' => 'web', 'path' => 'apps/site', 'web_root' => 'public', 'type' => 'laravel-app']]])
            ->assertConflict()
            ->assertJsonPath('error.code', 'project.app_in_use')
            ->assertJsonPath('error.details.app', 'docs');

        expect(array_column($project->refresh()->apps, 'name'))->toBe(['docs', 'web']);
    });

    it('switches inheriting default development instances when default_branch changes', function (): void {
        $explicit = Instance::query()->create([
            'project_id' => $this->fixture->project->id,
            'node_id' => $this->fixture->node->id,
            'name' => 'release',
            'environment' => 'development',
            'source_layout' => InstanceSourceLayout::Checkout->value,
            'checkout_path' => '/srv/orbit/apps/acme/release',
            'branch' => 'main',
            'branch_override' => 'main',
            'starting_commit' => str_repeat('b', 40),
            'status' => InstanceState::Active,
        ]);
        $routeId = $this->fixture->defaultRoute->id;
        $path = $this->fixture->defaultInstance->checkout_path;

        $this
            ->withHeader('X-Orbit-Request-Id', (string) Str::uuid())
            ->patchJson('/api/v1/projects/'.$this->fixture->project->id, [
                'default_branch' => 'stable',
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $this->fixture->project->id)
            ->assertJsonPath('data.default_branch', 'stable')
            ->assertJsonMissingPath('data.main_branch');

        expect($this->fixture->defaultInstance->refresh()->branch)
            ->toBe('stable')
            ->and($this->fixture->defaultInstance->name)
            ->toBe('default')
            ->and($this->fixture->defaultInstance->checkout_path)
            ->toBe($path)
            ->and($this->fixture->defaultRoute->refresh()->id)
            ->toBe($routeId)
            ->and($explicit->refresh()->branch)
            ->toBe('main')
            ->and($explicit->branch_override)
            ->toBe('main')
            ->and($this->fixture->sources->switchedInstances)
            ->toBe([$this->fixture->defaultInstance->id]);
    });

    it('changes a repository access URL while preserving canonical identity', function (): void {
        $https = 'https://github.com/acme/site.git';

        $this
            ->patchJson('/api/v1/projects/'.$this->fixture->project->id, [
                'repository_url' => $https,
            ])
            ->assertOk()
            ->assertJsonPath('data.repository_url', $https)
            ->assertJsonPath('data.slug', 'acme');

        expect($this->fixture->project->refresh()->repository_identity)
            ->toBe('github.com/acme/site')
            ->and($this->fixture->sources->originMutations)
            ->toBe(['/srv/orbit/apps/acme/default']);
    });

    it('refuses a repository identity owned by another App before mutation', function (): void {
        Project::query()->create([
            'name' => 'Other',
            'slug' => 'other',
            'repository_url' => 'git@github.com:acme/other.git',
            'default_branch' => 'main',
            'apps' => fixture_apps('public'),
        ]);

        $this
            ->patchJson('/api/v1/projects/'.$this->fixture->project->id, [
                'repository_url' => 'https://github.com/acme/other.git',
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'project.repository_identity_conflict');

        expect($this->fixture->project->refresh()->repository_url)
            ->toBe('git@github.com:acme/site.git')
            ->and($this->fixture->sources->originMutations)
            ->toBe([]);
    });

    it('retains production branch commit source and deployment ownership', function (): void {
        $production = Instance::query()->create([
            'project_id' => $this->fixture->project->id,
            'node_id' => $this->fixture->node->id,
            'name' => 'prod',
            'environment' => 'production',
            'source_layout' => 'release',
            'checkout_path' => '/srv/acme/releases/20260915',
            'production_home' => '/srv/acme',
            'production_user' => 'acme-prod',
            'branch' => 'release',
            'deployment_branch' => 'release',
            'starting_commit' => str_repeat('c', 40),
            'status' => InstanceState::Active,
        ]);

        $this
            ->patchJson('/api/v1/projects/'.$this->fixture->project->id, [
                'slug' => 'shop',
                'default_branch' => 'stable',
                'repository_url' => 'https://github.com/acme/site.git',
            ])
            ->assertOk()
            ->assertJsonPath('data.slug', 'shop')
            ->assertJsonPath('data.default_branch', 'stable');

        $production->refresh();

        expect($production->branch)
            ->toBe('release')
            ->and($production->deployment_branch)
            ->toBe('release')
            ->and($production->starting_commit)
            ->toBe(str_repeat('c', 40))
            ->and($production->checkout_path)
            ->toBe('/srv/acme/releases/20260915')
            ->and($production->production_home)
            ->toBe('/srv/acme')
            ->and($production->source_layout)
            ->toBe('release');
    });

    it('exposes default_branch and rejects main_branch on App updates', function (): void {
        $this
            ->patchJson('/api/v1/projects/'.$this->fixture->project->id, [
                'main_branch' => 'stable',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.body.0', 'The request body contains unsupported top-level keys.');

        expect($this->fixture->project->refresh()->default_branch)->toBe('main');
    });

    it('records project:update activity without main_branch', function (): void {
        $requestId = (string) Str::uuid();

        $this
            ->withHeader('X-Orbit-Request-Id', $requestId)
            ->patchJson('/api/v1/projects/'.$this->fixture->project->id, [
                'default_branch' => 'stable',
            ])
            ->assertOk();

        $activity = Activity::query()->where('request_id', $requestId)->sole();

        expect($activity->command)
            ->toBe('project:update')
            ->and($activity->properties->toArray())
            ->not
            ->toHaveKey('main_branch')
            ->and(json_encode($activity->properties->toArray()))
            ->not
            ->toContain('main_branch');
    });

    it('replaces generated Routes when the Project slug changes', function (): void {
        $oldRouteId = $this->fixture->defaultRoute->id;

        $this
            ->patchJson('/api/v1/projects/'.$this->fixture->project->id, [
                'slug' => 'shop',
            ])
            ->assertOk()
            ->assertJsonPath('data.slug', 'shop');

        $route = Route::query()->where('project_id', $this->fixture->project->id)->sole();

        expect($route->id)
            ->not
            ->toBe($oldRouteId)
            ->and($route->domain)
            ->toBe('shop.test')
            ->and($route->provenance)
            ->toBe(RouteProvenance::Generated)
            ->and(Route::query()->find($oldRouteId))
            ->toBeNull();
    });
});
