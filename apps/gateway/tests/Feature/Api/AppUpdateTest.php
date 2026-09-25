<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Activity;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Support\Str;
use Tests\Support\Orb101AppUpdateFixture;

beforeEach(function (): void {
    $this->operator = $this->markAsGateway(Node::query()->create([
        'name' => 'operator',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    $this->fixture = Orb101AppUpdateFixture::bind($this);
});

describe('app updates', function (): void {
    it('sets and clears the task baseline command on an existing Project', function (): void {
        $command = 'composer check --token=baseline-secret';

        $response = $this->patchJson('/api/v1/apps/'.$this->fixture->app->id, [
            'task_baseline_check' => $command,
        ])->assertOk();

        expect($this->fixture->app->refresh()->taskBaselineCheck())
            ->toBe($command)
            ->and($response->getContent())
            ->not->toContain($command, 'baseline-secret')
            ->and(json_encode(Activity::query()->latest('id')->first()?->properties))
            ->not->toContain($command, 'baseline-secret');

        $this->patchJson('/api/v1/apps/'.$this->fixture->app->id, [
            'task_baseline_check' => null,
        ])->assertOk();

        expect($this->fixture->app->refresh()->taskBaselineCheck())->toBeNull();
    });

    it('switches inheriting default development instances when default_branch changes', function (): void {
        $explicit = AppInstance::query()->create([
            'app_id' => $this->fixture->app->id,
            'node_id' => $this->fixture->node->id,
            'name' => 'release',
            'environment' => 'development',
            'source_layout' => AppInstanceSourceLayout::Checkout->value,
            'checkout_path' => '/srv/orbit/apps/acme/release',
            'branch' => 'main',
            'branch_override' => 'main',
            'starting_commit' => str_repeat('b', 40),
            'status' => AppInstanceState::Active,
        ]);
        $routeId = $this->fixture->defaultRoute->id;
        $path = $this->fixture->defaultInstance->checkout_path;

        $this
            ->withHeader('X-Orbit-Request-Id', (string) Str::uuid())
            ->patchJson('/api/v1/apps/'.$this->fixture->app->id, [
                'default_branch' => 'stable',
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $this->fixture->app->id)
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
            ->patchJson('/api/v1/apps/'.$this->fixture->app->id, [
                'repository_url' => $https,
            ])
            ->assertOk()
            ->assertJsonPath('data.repository_url', $https)
            ->assertJsonPath('data.slug', 'acme');

        expect($this->fixture->app->refresh()->repository_identity)
            ->toBe('github.com/acme/site')
            ->and($this->fixture->sources->originMutations)
            ->toBe(['/srv/orbit/apps/acme/default']);
    });

    it('refuses a repository identity owned by another App before mutation', function (): void {
        OrbitApp::query()->create([
            'name' => 'Other',
            'slug' => 'other',
            'repository_url' => 'git@github.com:acme/other.git',
            'default_branch' => 'main',
            'root' => 'public',
        ]);

        $this
            ->patchJson('/api/v1/apps/'.$this->fixture->app->id, [
                'repository_url' => 'https://github.com/acme/other.git',
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'app.repository_identity_conflict');

        expect($this->fixture->app->refresh()->repository_url)
            ->toBe('git@github.com:acme/site.git')
            ->and($this->fixture->sources->originMutations)
            ->toBe([]);
    });

    it('retains production branch commit source and deployment ownership', function (): void {
        $production = AppInstance::query()->create([
            'app_id' => $this->fixture->app->id,
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
            'status' => AppInstanceState::Active,
        ]);

        $this
            ->patchJson('/api/v1/apps/'.$this->fixture->app->id, [
                'slug' => 'shop',
                'default_branch' => 'stable',
                'root' => 'web/public',
                'repository_url' => 'https://github.com/acme/site.git',
            ])
            ->assertOk()
            ->assertJsonPath('data.slug', 'shop')
            ->assertJsonPath('data.default_branch', 'stable')
            ->assertJsonPath('data.root', 'web/public');

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
            ->patchJson('/api/v1/apps/'.$this->fixture->app->id, [
                'main_branch' => 'stable',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.body.0', 'The request body contains unsupported top-level keys.');

        expect($this->fixture->app->refresh()->default_branch)->toBe('main');
    });

    it('records app:update activity without main_branch', function (): void {
        $requestId = (string) Str::uuid();

        $this
            ->withHeader('X-Orbit-Request-Id', $requestId)
            ->patchJson('/api/v1/apps/'.$this->fixture->app->id, [
                'default_branch' => 'stable',
            ])
            ->assertOk();

        $activity = Activity::query()->where('request_id', $requestId)->sole();

        expect($activity->command)
            ->toBe('app:update')
            ->and($activity->properties->toArray())
            ->not
            ->toHaveKey('main_branch')
            ->and(json_encode($activity->properties->toArray()))
            ->not
            ->toContain('main_branch');
    });

    it('replaces generated Routes when the App slug changes', function (): void {
        $oldRouteId = $this->fixture->defaultRoute->id;

        $this
            ->patchJson('/api/v1/apps/'.$this->fixture->app->id, [
                'slug' => 'shop',
            ])
            ->assertOk()
            ->assertJsonPath('data.slug', 'shop');

        $route = Route::query()->where('app_id', $this->fixture->app->id)->sole();

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
