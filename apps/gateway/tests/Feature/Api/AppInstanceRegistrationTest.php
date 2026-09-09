<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceDestinationGuard;
use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\DevelopmentAppInstanceConfigurator;
use App\Domain\AppInstances\DevelopmentRouteProjector;
use App\Domain\AppInstances\DevelopmentSourceProfile;
use App\Domain\AppInstances\Registration\RegistrationSourceFacts;
use App\Domain\AppInstances\Registration\RegistrationSourceManager;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\RepositoryDefaultBranchResolver;
use App\Models\Activity;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Route;

beforeEach(function (): void {
    $this->node = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'tld' => 'test',
        'public_ssh_host' => '192.0.2.10',
        'wireguard_ip' => '10.44.0.10',
        'user' => 'orbit',
        'settings' => ['apps' => ['path' => '/srv/orbit/apps']],
    ]);
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $this->markAsGateway($this->node);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.10']);

    app()->instance(ManagedUserAccountResolver::class, new class implements ManagedUserAccountResolver {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
        }
    });
    app()->instance(AppInstanceDestinationGuard::class, new class implements AppInstanceDestinationGuard {
        public function assertUnoccupied(Node $node, App\Domain\Nodes\Storage\StoragePath $destination): void {}
    });
    app()->instance(RepositoryDefaultBranchResolver::class, new class implements RepositoryDefaultBranchResolver {
        public function resolve(string $repository): string
        {
            return 'main';
        }

        public function verify(string $repository, string $branch): void {}
    });
    app()->instance(DevelopmentAppInstanceConfigurator::class, new class implements DevelopmentAppInstanceConfigurator {
        public function inspect(AppInstance $appInstance): DevelopmentSourceProfile
        {
            return new DevelopmentSourceProfile('8.5', true);
        }

        public function configureLaravelUrl(AppInstance $appInstance, string $url): void {}
    });
    $this->projection = new class implements DevelopmentRouteProjector {
        public bool $fail = false;

        public function converge(AppInstance $appInstance, Route $route): void
        {
            if ($this->fail) {
                throw new ResourceOperationException('instance.projection_failed', 'Projection failed.');
            }
        }
    };
    app()->instance(DevelopmentRouteProjector::class, $this->projection);
    $this->registrationSource = new class implements RegistrationSourceManager {
        /** @var list<RegistrationSourceFacts> */
        public array $facts = [];

        public bool $invalid = false;

        /** @var list<string> */
        public array $calls = [];

        public function inspect(Node $node, string $sourcePath, bool $includeWorktrees): array
        {
            $this->calls[] = 'inspect';
            if ($this->invalid) {
                throw new ResourceOperationException('instance.source_invalid', 'Invalid source.', 422);
            }

            return $this->facts;
        }

        public function relocate(AppInstance $appInstance, RegistrationSourceFacts $facts): void
        {
            $this->calls[] = 'relocate';
        }

        public function relocateSet(array $members): void
        {
            $this->calls[] = 'relocate-set:'.count($members);
        }

        public function restoreOriginal(AppInstance $appInstance, RegistrationSourceFacts $facts): void
        {
            $this->calls[] = 'restore-original';
        }

        public function prepareLaravelRollback(AppInstance $appInstance): void
        {
            $this->calls[] = 'url-prepare';
        }

        public function restoreLaravelConfiguration(AppInstance $appInstance): void
        {
            $this->calls[] = 'url-restore';
        }

        public function discardLaravelRollback(AppInstance $appInstance): void
        {
            $this->calls[] = 'url-discard';
        }
    };
    $this->registrationSource->facts = [registration_facts()];
    app()->instance(RegistrationSourceManager::class, $this->registrationSource);
});

it('refuses a non Git source before any registration mutation', function (): void {
    $this->registrationSource->invalid = true;

    $this
        ->postJson('/api/v1/instances/register', ['source_path' => '/tmp/not-git'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'instance.source_invalid');

    expect(OrbitApp::query()->count())
        ->toBe(0)
        ->and(AppInstance::query()->count())
        ->toBe(0)
        ->and(Route::query()->count())
        ->toBe(0)
        ->and($this->registrationSource->calls)
        ->toBe(['inspect']);
});

it('resolves an App by canonical repository identity and returns bounded source state', function (): void {
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);

    $this
        ->postJson('/api/v1/instances/register', ['source_path' => '/work/acme'])
        ->assertOk()
        ->assertJsonPath('data.app.id', $app->id)
        ->assertJsonPath('data.app_instance.name', 'default')
        ->assertJsonPath('data.app_instance.source_layout', 'checkout')
        ->assertJsonPath('data.app_instance.checkout_path', '/srv/orbit/apps/acme/default')
        ->assertJsonPath('data.app_instance.selected_branch', 'main')
        ->assertJsonPath('data.app_instance.detached', false)
        ->assertJsonPath('data.app_instance.starting_commit', str_repeat('a', 40))
        ->assertJsonPath('data.app_instance.status', 'active')
        ->assertJsonPath('data.source_count', 1)
        ->assertJsonPath('data.completed_count', 1);

    $activity = Activity::query()->where('command', 'instance:register')->sole();
    expect($activity->subject_type)
        ->toBe(AppInstance::class)
        ->and($activity->target_node_id)
        ->toBe($this->node->id)
        ->and($activity->properties?->get('source_layout'))
        ->toBe('checkout')
        ->and($activity->properties?->get('input'))
        ->not->toHaveKey('source_path');
});

it('creates a confirmed missing App before its AppInstance and retains it after later failure', function (): void {
    $payload = [
        'source_path' => '/work/acme',
        'app_slug' => 'acme',
        'default_branch' => 'main',
        'root' => 'public',
    ];

    $this
        ->postJson('/api/v1/instances/register', $payload)
        ->assertCreated()
        ->assertJsonPath('data.app.slug', 'acme')
        ->assertJsonPath('data.app_instance.name', 'default');

    expect(OrbitApp::query()->count())->toBe(1)->and(AppInstance::query()->count())->toBe(1);
});

it('requires unresolved values without mutating and keeps a valid App on incomplete registration', function (): void {
    $facts = registration_facts();
    $this->registrationSource->facts = [new RegistrationSourceFacts(
        path: $facts->path,
        layout: $facts->layout,
        repositoryUrl: $facts->repositoryUrl,
        repositoryIdentity: $facts->repositoryIdentity,
        branch: $facts->branch,
        detached: $facts->detached,
        commit: $facts->commit,
        defaultBranch: null,
        inferredSlug: $facts->inferredSlug,
        inferredRoot: null,
        commonRepositoryPath: $facts->commonRepositoryPath,
        worktreePaths: $facts->worktreePaths,
        sourceDigest: $facts->sourceDigest,
    )];

    $this
        ->postJson('/api/v1/instances/register', ['source_path' => '/work/acme'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'instance.registration_values_unresolved');
    expect(OrbitApp::query()->count())->toBe(0)->and(AppInstance::query()->count())->toBe(0);

    $this->registrationSource->facts = [registration_facts()];
    $this->projection->fail = true;
    $this
        ->postJson('/api/v1/instances/register', [
            'source_path' => '/work/acme',
            'app_slug' => 'acme',
            'default_branch' => 'main',
            'root' => 'public',
        ])
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'instance.registration_incomplete')
        ->assertJsonPath(
            'error.message',
            'App [acme] was retained; AppInstance registration is incomplete and can be retried.',
        );

    expect(OrbitApp::query()->count())
        ->toBe(1)
        ->and(AppInstance::query()->sole()->status->value)
        ->toBe('source_resolved')
        ->and($this->registrationSource->calls)
        ->toContain('url-restore');
});

it('returns the same identities on an identical retry and refuses conflicting evidence', function (): void {
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $payload = ['source_path' => '/work/acme', 'app_id' => $app->id];
    $first = $this->postJson('/api/v1/instances/register', $payload)->assertOk();
    $this->registrationSource->invalid = true;
    $second = $this->postJson('/api/v1/instances/register', $payload)->assertOk();
    expect($second->json('data.app_instance.id'))
        ->toBe($first->json('data.app_instance.id'))
        ->and($second->json('data.app_instance.route.id'))
        ->toBe($first->json('data.app_instance.route.id'))
        ->and(AppInstance::query()->count())
        ->toBe(1)
        ->and(Route::query()->count())
        ->toBe(1);

    $this
        ->postJson('/api/v1/instances/register', [...$payload, 'app_slug' => 'different'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'app.identity_conflict');
    expect(AppInstance::query()->count())->toBe(1)->and(Route::query()->count())->toBe(1);
});

it('restores a failed default migration and completes the identical retry with stable identities', function (): void {
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $this->node->id,
        'name' => 'main',
        'source_layout' => 'checkout',
        'checkout_path' => '/work/acme',
        'branch' => 'main',
        'migration_required' => true,
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => '8.4',
        'source_is_laravel' => false,
        'status' => 'active',
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $this->node->id,
        'generation_basis_node_id' => null,
        'hostname' => 'acme.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $payload = ['source_path' => '/work/acme', 'app_id' => $app->id];
    $authoritativeBefore = $instance->only([
        'name',
        'source_layout',
        'checkout_path',
        'root',
        'branch',
        'migration_required',
        'starting_commit',
        'selected_php_version',
        'source_is_laravel',
        'status',
    ]);
    $routeBefore = $route->only([
        'id',
        'node_id',
        'cluster_id',
        'generation_basis_node_id',
        'hostname',
        'provenance',
        'publication',
        'status',
    ]);
    $this->projection->fail = true;

    $this
        ->postJson('/api/v1/instances/register', $payload)
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'instance.registration_incomplete');

    expect($instance->refresh()->only(array_keys($authoritativeBefore)))
        ->toBe($authoritativeBefore)
        ->and($route->refresh()->only(array_keys($routeBefore)))
        ->toBe($routeBefore)
        ->and($route->targets()->sole()->app_instance_id)
        ->toBe($instance->id)
        ->and($instance->failed_step)
        ->toBe('registration')
        ->and($instance->error_code)
        ->toBe('instance.projection_failed')
        ->and($this->registrationSource->calls)
        ->toBe(['inspect', 'relocate-set:1', 'url-prepare', 'url-restore', 'restore-original']);

    $this->projection->fail = false;
    $response = $this->postJson('/api/v1/instances/register', $payload)->assertOk();

    expect($response->json('data.app_instance.id'))
        ->toBe($instance->id)
        ->and($response->json('data.app_instance.route.id'))
        ->toBe($route->id)
        ->and($response->json('data.app_instance.name'))
        ->toBe('default')
        ->and($response->json('data.app_instance.checkout_path'))
        ->toBe('/srv/orbit/apps/acme/default')
        ->and($response->json('data.app_instance.migration_required'))
        ->toBeFalse()
        ->and($instance->refresh()->selected_php_version)
        ->toBe('8.5')
        ->and($route->refresh()->hostname)
        ->toBe('acme.test')
        ->and(AppInstance::query()->count())
        ->toBe(1)
        ->and(Route::query()->count())
        ->toBe(1)
        ->and($this->registrationSource->calls)
        ->toBe([
            'inspect',
            'relocate-set:1',
            'url-prepare',
            'url-restore',
            'restore-original',
            'relocate-set:1',
            'url-prepare',
            'url-discard',
        ]);
});

function registration_facts(string $digest = ''): RegistrationSourceFacts
{
    return new RegistrationSourceFacts(
        path: '/work/acme',
        layout: AppInstanceSourceLayout::Checkout,
        repositoryUrl: 'git@github.com:acme/acme.git',
        repositoryIdentity: 'github.com/acme/acme',
        branch: 'main',
        detached: false,
        commit: str_repeat('a', 40),
        defaultBranch: 'main',
        inferredSlug: 'acme',
        inferredRoot: 'public',
        commonRepositoryPath: '/work/acme/.git',
        worktreePaths: ['/work/acme'],
        sourceDigest: $digest === '' ? str_repeat('c', 64) : $digest,
    );
}
