<?php

declare(strict_types=1);

use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\ComposerSourceClassifier;
use App\Domain\Instances\DevelopmentInstanceConfigurator;
use App\Domain\Instances\DevelopmentRouteProjector;
use App\Domain\Instances\DevelopmentSourceProfile;
use App\Domain\Instances\InstanceDestinationGuard;
use App\Domain\Instances\InstancePhpVersionCatalog;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\Registration\RegistrationSourceFacts;
use App\Domain\Instances\Registration\RegistrationSourceManager;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Routes\RouteDomainProjector;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\RepositoryDefaultBranchResolver;
use App\Models\Activity;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Support\Str;

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

    app()->instance(ManagedUserAccountResolver::class, new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
        }
    });
    $this->destinationGuard = new class implements InstanceDestinationGuard
    {
        /** @var list<string> */
        public array $paths = [];

        public function assertUnoccupied(Node $node, StoragePath $destination): void
        {
            $this->paths[] = $destination->value;
        }
    };
    app()->instance(InstanceDestinationGuard::class, $this->destinationGuard);
    app()->instance(RepositoryDefaultBranchResolver::class, new class implements RepositoryDefaultBranchResolver
    {
        public function resolve(string $repository, ProjectSourceAccess $source): string
        {
            return 'main';
        }

        public function verify(string $repository, string $branch, ProjectSourceAccess $source): void {}
    });
    $this->configuration = new class implements DevelopmentInstanceConfigurator
    {
        public ?string $unsafePath = null;

        /** @var list<string> */
        public array $inspected = [];

        public function inspect(Instance $instance): DevelopmentSourceProfile
        {
            $this->inspected[] = $instance->checkout_path;

            if ($instance->checkout_path === $this->unsafePath) {
                throw new RuntimeConvergenceException(
                    'source-classification',
                    'app-dev.source_metadata_unsafe',
                    'The development source metadata is invalid or unsupported.',
                );
            }

            return new DevelopmentSourceProfile('8.5', true);
        }

        public function configureLaravelUrl(Instance $instance, string $url): void {}
    };
    app()->instance(DevelopmentInstanceConfigurator::class, $this->configuration);
    $this->projection = new class implements DevelopmentRouteProjector
    {
        public bool $fail = false;

        public function converge(Instance $instance, Route $route): void
        {
            if ($this->fail) {
                throw new ResourceOperationException('instance.projection_failed', 'Projection failed.');
            }
        }
    };
    app()->instance(DevelopmentRouteProjector::class, $this->projection);
    $this->registrationSource = new class implements RegistrationSourceManager
    {
        /** @var list<RegistrationSourceFacts> */
        public array $facts = [];

        public bool $invalid = false;

        public bool $retainedInvalid = false;

        public bool $failDiscardOnce = false;

        public bool $failRelocateOnce = false;

        /** @var list<string> */
        public array $invalidRelocationPaths = [];

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

        public function validateRetained(
            Node $node,
            RegistrationSourceFacts $facts,
            string $authoritativePath,
        ): void {
            $this->calls[] = 'validate:'.$authoritativePath;

            if ($this->retainedInvalid) {
                throw new ResourceOperationException(
                    'instance.registration_conflict',
                    'The retained authoritative registration source no longer matches its verified Git identity.',
                    409,
                );
            }
        }

        public function validateRelocationRecovery(
            Node $node,
            RegistrationSourceFacts $facts,
            string $candidatePath,
        ): void {
            $this->calls[] = 'validate-relocation:'.$candidatePath;

            if ($this->retainedInvalid || in_array($candidatePath, $this->invalidRelocationPaths, true)) {
                throw new ResourceOperationException(
                    'instance.registration_conflict',
                    'The retained relocation path no longer matches its preserved source state.',
                    409,
                );
            }
        }

        public function relocate(Instance $instance, RegistrationSourceFacts $facts): void
        {
            $this->calls[] = 'relocate';
        }

        public function relocateSet(array $members): void
        {
            $this->calls[] = 'relocate-set:'.count($members);

            if ($this->failRelocateOnce) {
                $this->failRelocateOnce = false;

                throw new ResourceOperationException('instance.relocation_failed', 'Relocation failed.');
            }

            foreach ($members as $member) {
                Instance::query()
                    ->whereKey($member['instance']->id)
                    ->update([
                        'registration_relocation_state' => 'relocated',
                        'registration_authoritative_path' => $member['instance']->checkout_path,
                    ]);
            }
        }

        public function restoreOriginal(Instance $instance, RegistrationSourceFacts $facts): void
        {
            $this->calls[] = 'restore-original';
            Instance::query()
                ->whereKey($instance->id)
                ->update([
                    'registration_relocation_state' => 'reserved',
                    'registration_authoritative_path' => $facts->path,
                ]);
        }

        public function prepareLaravelRollback(Instance $instance): void
        {
            $this->calls[] = 'url-prepare';
        }

        public function restoreLaravelConfiguration(Instance $instance): void
        {
            $this->calls[] = 'url-restore';
        }

        public function discardLaravelRollback(Instance $instance): void
        {
            $this->calls[] = 'url-discard';

            if ($this->failDiscardOnce) {
                $this->failDiscardOnce = false;

                throw new ResourceOperationException(
                    'instance.laravel_rollback_failed',
                    'Laravel receipt cleanup was interrupted.',
                );
            }
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

    expect(Project::query()->count())
        ->toBe(0)
        ->and(Instance::query()->count())
        ->toBe(0)
        ->and(Route::query()->count())
        ->toBe(0)
        ->and($this->registrationSource->calls)
        ->toBe(['inspect']);
});

it('binds registration seed selection to the adopted source commit', function (bool $matching): void {
    $project = Project::query()->create([
        'name' => 'Acme', 'slug' => 'acme', 'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main', 'root' => 'public',
    ]);
    $home = '/srv/orbit/apps/acme/default';
    $commit = str_repeat($matching ? 'a' : 'b', 40);
    Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $this->node->id, 'name' => 'default',
        'checkout_path' => $home, 'status' => InstanceState::Active,
        'seed_path' => $home, 'seed_commit' => $commit, 'seed_repository' => $home,
    ]);
    $this->registrationSource->facts = [registration_facts(path: '/work/acme-copy')];
    $this->postJson('/api/v1/instances/register', ['source_path' => '/work/acme-copy', 'instance_name' => 'registered-copy'])
        ->assertOk()->assertJsonPath('data.instance.starting_commit', str_repeat('a', 40))
        ->assertJsonPath('data.instance.seed_commit', $matching ? $commit : null);
    $registered = Instance::query()->where('name', 'registered-copy')->sole();
    expect($registered->seed_selected)->toBeTrue()
        ->and($registered->seed_path)->toBe($matching ? $home : null);
})->with(['matching deployed commit' => true, 'newer deployed commit' => false]);

it('resolves a Project by canonical repository identity and returns bounded source state', function (): void {
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);

    $this
        ->postJson('/api/v1/instances/register', ['source_path' => '/work/acme'])
        ->assertOk()
        ->assertJsonPath('data.project.id', $project->id)
        ->assertJsonPath('data.instance.name', 'default')
        ->assertJsonPath('data.instance.source_layout', 'checkout')
        ->assertJsonPath('data.instance.checkout_path', '/srv/orbit/apps/acme/default')
        ->assertJsonPath('data.instance.selected_branch', 'main')
        ->assertJsonPath('data.instance.detached', false)
        ->assertJsonPath('data.instance.starting_commit', str_repeat('a', 40))
        ->assertJsonPath('data.instance.status', 'active')
        ->assertJsonPath('data.source_count', 1)
        ->assertJsonPath('data.completed_count', 1);

    $activity = Activity::query()->where('command', 'instance:register')->sole();
    expect($activity->subject_type)
        ->toBe('instance')
        ->and($activity->target_node_id)
        ->toBe($this->node->id)
        ->and($activity->properties?->get('source_layout'))
        ->toBe('checkout')
        ->and($activity->properties?->get('input'))
        ->not->toHaveKey('source_path');
});

it('refuses registration when no Project owns the repository and points to project:create', function (): void {
    $this
        ->postJson('/api/v1/instances/register', ['source_path' => '/work/acme'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'instance.project_missing')
        ->assertJsonPath(
            'error.message',
            'No Project owns repository [git@github.com:acme/acme.git]. Create it with `orbit project:create` first.',
        );

    expect(Project::query()->count())
        ->toBe(0)
        ->and(Instance::query()->count())
        ->toBe(0)
        ->and(Route::query()->count())
        ->toBe(0)
        ->and($this->registrationSource->calls)
        ->toBe(['inspect']);
});

it('refuses the removed Project creation fields', function (string $field, string $value): void {
    $this
        ->postJson('/api/v1/instances/register', ['source_path' => '/work/acme', $field => $value])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');

    expect(Project::query()->count())->toBe(0)->and($this->registrationSource->calls)->toBe([]);
})->with([
    'project slug' => ['project_slug', 'acme'],
    'project name' => ['project_name', 'Acme'],
    'default branch' => ['default_branch', 'main'],
]);

it('keeps the adopted source for a retry when registration is incomplete', function (): void {
    Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $this->projection->fail = true;

    $this
        ->postJson('/api/v1/instances/register', ['source_path' => '/work/acme'])
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'instance.registration_incomplete')
        ->assertJsonPath('error.message', 'Instance registration is incomplete and can be retried.');

    expect(Instance::query()->sole()->status->value)
        ->toBe('source_resolved')
        ->and($this->registrationSource->calls)
        ->toContain('url-restore');
});

it('returns the same identities on an identical retry and refuses conflicting evidence', function (): void {
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $payload = ['source_path' => '/work/acme', 'project_id' => $project->id];
    $first = $this->postJson('/api/v1/instances/register', $payload)->assertOk();
    $this->registrationSource->invalid = true;
    $second = $this->postJson('/api/v1/instances/register', $payload)->assertOk();
    expect($second->json('data.instance.id'))
        ->toBe($first->json('data.instance.id'))
        ->and($second->json('data.instance.route.id'))
        ->toBe($first->json('data.instance.route.id'))
        ->and(Instance::query()->count())
        ->toBe(1)
        ->and(Route::query()->count())
        ->toBe(1)
        ->and($this->registrationSource->calls)
        ->toBe([
            'inspect',
            'relocate-set:1',
            'url-prepare',
            'url-discard',
            'validate:/srv/orbit/apps/acme/default',
            'url-discard',
        ]);
});

it('preserves an ordinary retained root when retry input is omitted or identical and returns 409 for a conflict', function (): void {
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $payload = [
        'source_path' => '/work/acme',
        'project_id' => $project->id,
        'root' => 'web',
    ];

    $first = $this->postJson('/api/v1/instances/register', $payload)->assertOk();
    $omitted = $this->postJson('/api/v1/instances/register', [
        'source_path' => '/work/acme',
        'project_id' => $project->id,
    ])->assertOk();
    $identical = $this->postJson('/api/v1/instances/register', $payload)->assertOk();
    $instance = Instance::query()->sole();
    $route = Route::query()->sole();
    $before = $instance->refresh()->getAttributes();

    $this
        ->postJson('/api/v1/instances/register', [
            'source_path' => '/work/acme',
            'project_id' => $project->id,
            'root' => 'public',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.registration_conflict');

    expect($omitted->json('data.instance.id'))
        ->toBe($first->json('data.instance.id'))
        ->and($identical->json('data.instance.id'))
        ->toBe($first->json('data.instance.id'))
        ->and($instance->refresh()->getAttributes())
        ->toBe($before)
        ->and($instance->root)
        ->toBe('web')
        ->and($route->id)
        ->toBe($first->json('data.instance.route.id'));
});
it('retains explicit hostname intent before Route creation and rejects a changed retry with 409', function (): void {
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $payload = [
        'source_path' => '/work/acme',
        'project_id' => $project->id,
        'domain' => 'original.test',
    ];
    $this->registrationSource->failRelocateOnce = true;

    $this
        ->postJson('/api/v1/instances/register', $payload)
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'instance.registration_incomplete');

    $instance = Instance::query()->sole();
    $before = $instance->refresh()->getAttributes();

    $this
        ->postJson('/api/v1/instances/register', [
            'source_path' => '/work/acme',
            'project_id' => $project->id,
            'domain' => 'changed.test',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.registration_conflict');

    expect($instance->refresh()->getAttributes())
        ->toBe($before)
        ->and($instance->registration_route_domain)
        ->toBe('original.test')
        ->and($instance->registration_route_provenance)
        ->toBe(RouteProvenance::Explicit->value)
        ->and(Route::query()->count())
        ->toBe(0);

    $identical = $this->postJson('/api/v1/instances/register', $payload)->assertOk();
    $omitted = $this->postJson('/api/v1/instances/register', [
        'source_path' => '/work/acme',
        'project_id' => $project->id,
    ])->assertOk();

    expect($identical->json('data.instance.route.domain'))
        ->toBe('original.test')
        ->and($omitted->json('data.instance.route.id'))
        ->toBe($identical->json('data.instance.route.id'))
        ->and($omitted->json('data.instance.route.domain'))
        ->toBe('original.test');
});

it('preserves explicit hostname intent after Route creation and returns 409 for a changed retry', function (): void {
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $payload = [
        'source_path' => '/work/acme',
        'project_id' => $project->id,
        'domain' => 'preserved.test',
    ];
    $this->projection->fail = true;

    $this
        ->postJson('/api/v1/instances/register', $payload)
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'instance.registration_incomplete');

    $instance = Instance::query()->sole();
    $route = Route::query()->sole();
    $instanceBefore = $instance->refresh()->getAttributes();
    $routeBefore = $route->refresh()->getAttributes();

    $this
        ->postJson('/api/v1/instances/register', [
            'source_path' => '/work/acme',
            'project_id' => $project->id,
            'domain' => 'changed.test',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.registration_conflict');

    expect($instance->refresh()->getAttributes())
        ->toBe($instanceBefore)
        ->and($route->refresh()->getAttributes())
        ->toBe($routeBefore);

    $this->projection->fail = false;
    $omitted = $this->postJson('/api/v1/instances/register', [
        'source_path' => '/work/acme',
        'project_id' => $project->id,
    ])->assertOk();
    $identical = $this->postJson('/api/v1/instances/register', $payload)->assertOk();

    expect($omitted->json('data.instance.route.id'))
        ->toBe($route->id)
        ->and($omitted->json('data.instance.route.domain'))
        ->toBe('preserved.test')
        ->and($identical->json('data.instance.route.id'))
        ->toBe($route->id);
});

it('retains generated hostname provenance and returns 409 for a later explicit hostname', function (): void {
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $this->registrationSource->failRelocateOnce = true;

    $this
        ->postJson('/api/v1/instances/register', [
            'source_path' => '/work/acme',
            'project_id' => $project->id,
        ])
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'instance.registration_incomplete');

    $instance = Instance::query()->sole();
    $before = $instance->refresh()->getAttributes();

    $this
        ->postJson('/api/v1/instances/register', [
            'source_path' => '/work/acme',
            'project_id' => $project->id,
            'domain' => 'changed.test',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.registration_conflict');

    expect($instance->refresh()->getAttributes())
        ->toBe($before)
        ->and($instance->registration_route_domain)
        ->toBeNull()
        ->and($instance->registration_route_provenance)
        ->toBe(RouteProvenance::Generated->value)
        ->and(Route::query()->count())
        ->toBe(0);

    $response = $this->postJson('/api/v1/instances/register', [
        'source_path' => '/work/acme',
        'project_id' => $project->id,
    ])->assertOk();

    expect($response->json('data.instance.route.provenance'))
        ->toBe(RouteProvenance::Generated->value);
});

it('uses the current sole Route after publication for omitted, matching, and conflicting retries', function (
    bool $registrationCompleted,
): void {
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $payload = [
        'source_path' => '/work/acme',
        'project_id' => $project->id,
    ];
    $first = $this->postJson('/api/v1/instances/register', [
        ...$payload,
        'domain' => 'original.test',
    ])->assertOk();
    $instance = Instance::query()->sole();
    $route = Route::query()->sole();
    bind_route_domain_update_for_registration_test();

    $updated = $this
        ->patchJson("/api/v1/routes/{$route->id}", ['domain' => 'changed.test'])
        ->assertOk()
        ->assertJsonPath('data.domain', 'changed.test');
    $route = Route::query()->findOrFail($updated->json('data.id'));

    if (! $registrationCompleted) {
        $instance->update(['registration_completed_at' => null]);
    }

    $omitted = $this->postJson('/api/v1/instances/register', $payload)->assertOk();

    if (! $registrationCompleted) {
        $instance->update(['registration_completed_at' => null]);
    }

    $matching = $this->postJson('/api/v1/instances/register', [
        ...$payload,
        'domain' => 'changed.test',
    ])->assertOk();

    if (! $registrationCompleted) {
        $instance->update(['registration_completed_at' => null]);
    }

    $instanceBeforeConflict = $instance->refresh()->getAttributes();
    $routeBeforeConflict = $route->refresh()->getAttributes();
    $this
        ->postJson('/api/v1/instances/register', [
            ...$payload,
            'domain' => 'original.test',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.registration_conflict');

    expect($omitted->json('data.instance.id'))
        ->toBe($first->json('data.instance.id'))
        ->and($omitted->json('data.instance.route.id'))
        ->toBe($route->id)
        ->and($omitted->json('data.instance.route.domain'))
        ->toBe('changed.test')
        ->and($matching->json('data.instance.id'))
        ->toBe($instance->id)
        ->and($matching->json('data.instance.route.id'))
        ->toBe($route->id)
        ->and($instance->refresh()->getAttributes())
        ->toBe($instanceBeforeConflict)
        ->and($route->refresh()->getAttributes())
        ->toBe($routeBeforeConflict)
        ->and($instance->registration_route_domain)
        ->toBe('original.test');
})->with([
    'completed registration' => true,
    'active publication before registration completion' => false,
]);
it('refuses registration while the authoritative Route domain change is incomplete', function (): void {
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $payload = [
        'source_path' => '/work/acme',
        'project_id' => $project->id,
        'domain' => 'original.test',
    ];
    $this->postJson('/api/v1/instances/register', $payload)->assertOk();
    $instance = Instance::query()->sole();
    $route = Route::query()->sole();
    $replacement = Route::query()->create([
        'project_id' => $route->project_id,
        'node_id' => $route->node_id,
        'domain' => 'changed.test',
        'provenance' => $route->provenance,
        'publication' => $route->publication,
        'status' => RouteStatus::Pending,
        'replaces_route_id' => $route->id,
        'replacement_step' => RouteReplacementStep::Reserved,
    ]);
    $route->update(['replaced_by_route_id' => $replacement->id]);
    $instanceBefore = $instance->refresh()->getAttributes();
    $routeBefore = $route->refresh()->getAttributes();

    $this
        ->postJson('/api/v1/instances/register', $payload)
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.registration_conflict');

    expect($instance->refresh()->getAttributes())
        ->toBe($instanceBefore)
        ->and($route->refresh()->getAttributes())
        ->toBe($routeBefore);
});

it('refuses colliding complete-set identities before reservation on every retry', function (
    array $paths,
    ?string $name,
): void {
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $this->registrationSource->facts = registration_set_facts($paths);
    $payload = [
        'source_path' => $paths[0],
        'include_worktrees' => true,
        'project_id' => $project->id,
        ...($name === null ? [] : ['instance_name' => $name]),
    ];

    foreach ([1, 2] as $attempt) {
        $this
            ->postJson('/api/v1/instances/register', $payload)
            ->assertConflict()
            ->assertJsonPath('error.code', 'instance.identity_conflict');

        expect(Instance::query()->count())
            ->toBe(0, "attempt {$attempt}")
            ->and(Route::query()->count())
            ->toBe(0, "attempt {$attempt}");
    }

    expect($this->registrationSource->calls)->toBe(['inspect', 'inspect']);
})->with([
    'equal basenames' => [
        ['/work/primary/shared', '/work/linked/shared'],
        null,
    ],
    'explicit primary name matches another member' => [
        ['/work/primary/source', '/work/linked/feature'],
        'feature',
    ],
]);

it('refuses retained registration evidence that omits one requested worktree', function (): void {
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $paths = ['/work/primary/source', '/work/linked/feature'];
    $requestId = (string) Str::uuid();
    Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $this->node->id,
        'name' => 'source',
        'source_layout' => 'checkout',
        'checkout_path' => '/srv/orbit/apps/acme/source',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'registration_original_path' => $paths[0],
        'registration_request_id' => $requestId,
        'registration_primary' => true,
        'registration_include_worktrees' => true,
        'registration_repository_url' => 'git@github.com:acme/acme.git',
        'registration_repository_identity' => 'github.com/acme/acme',
        'registration_source_digest' => str_repeat('c', 64),
        'registration_default_branch' => 'main',
        'registration_inferred_slug' => 'acme',
        'registration_inferred_root' => 'public',
        'registration_common_repository_path' => '/work/primary/source/.git',
        'registration_worktree_paths' => $paths,
        'registration_relocation_state' => 'reserved',
        'registration_authoritative_path' => $paths[0],
        'status' => 'reserved',
    ]);

    foreach ([1, 2] as $attempt) {
        $this
            ->postJson('/api/v1/instances/register', [
                'source_path' => $paths[0],
                'include_worktrees' => true,
                'project_id' => $project->id,
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'instance.registration_evidence_invalid');

        expect(Instance::query()->count())
            ->toBe(1, "attempt {$attempt}")
            ->and(Route::query()->count())
            ->toBe(0, "attempt {$attempt}");
    }

    expect($this->registrationSource->calls)->toBe([]);
});

it('returns 409 for a retained secondary request and keeps the complete primary retry resumable', function (
    bool $includeWorktrees,
    bool $relatedMemberDiscovery,
): void {
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $paths = ['/work/acme', '/work/feature'];
    $facts = registration_set_facts($paths);
    $requestId = (string) Str::uuid();
    $instances = collect($facts)->map(function (RegistrationSourceFacts $fact, int $index) use (
        $project,
        $requestId,
    ): Instance {
        return Instance::query()->create([
            'project_id' => $project->id,
            'node_id' => $this->node->id,
            'name' => $index === 0 ? 'default' : 'feature',
            'source_layout' => $fact->layout,
            'checkout_path' => $index === 0
                ? '/srv/orbit/apps/acme/default'
                : '/srv/orbit/apps/acme/feature',
            'branch' => $fact->branch,
            'starting_commit' => $fact->commit,
            'registration_original_path' => $fact->path,
            'registration_request_id' => $requestId,
            'registration_primary' => $index === 0,
            'registration_include_worktrees' => true,
            'registration_repository_url' => $fact->repositoryUrl,
            'registration_repository_identity' => $fact->repositoryIdentity,
            'registration_source_digest' => $fact->sourceDigest,
            'registration_detached' => $fact->detached,
            'registration_default_branch' => $fact->defaultBranch,
            'registration_inferred_slug' => $fact->inferredSlug,
            'registration_inferred_root' => $fact->inferredRoot,
            'registration_common_repository_path' => $fact->commonRepositoryPath,
            'registration_worktree_paths' => $fact->worktreePaths,
            'registration_relocation_state' => 'reserved',
            'registration_authoritative_path' => $fact->path,
            'registration_route_domain' => $index === 0 ? 'primary.test' : null,
            'registration_route_provenance' => $index === 0
                ? RouteProvenance::Explicit->value
                : RouteProvenance::Generated->value,
            'status' => InstanceState::Reserved,
        ]);
    });
    $before = Instance::query()
        ->orderBy('id')
        ->get()
        ->mapWithKeys(
            static fn (Instance $instance): array => [$instance->id => $instance->getAttributes()],
        )
        ->all();
    $submittedPath = $paths[1];

    if ($relatedMemberDiscovery) {
        $submittedPath = '/work/new-primary';
        $this->registrationSource->facts = registration_set_facts([$submittedPath, $paths[1]]);
    }

    $this
        ->postJson('/api/v1/instances/register', [
            'source_path' => $submittedPath,
            'include_worktrees' => $includeWorktrees,
            'project_id' => $project->id,
            'instance_name' => 'intruder',
            'domain' => 'intruder.test',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.registration_conflict');

    expect(
        Instance::query()
            ->orderBy('id')
            ->get()
            ->mapWithKeys(
                static fn (Instance $instance): array => [$instance->id => $instance->getAttributes()],
            )
            ->all(),
    )
        ->toBe($before)
        ->and(Route::query()->count())
        ->toBe(0)
        ->and($this->registrationSource->calls)
        ->toBe($relatedMemberDiscovery ? ['inspect'] : []);
    $this->registrationSource->calls = [];

    $response = $this->postJson('/api/v1/instances/register', [
        'source_path' => $paths[0],
        'include_worktrees' => true,
        'project_id' => $project->id,
        'instance_name' => 'default',
        'domain' => 'primary.test',
    ])->assertOk();
    $response->assertJsonMissingPath('data.app_instances');
    $completed = collect($response->json('data.instances'))->keyBy('name');

    expect($response->json('data.source_count'))
        ->toBe(2)
        ->and($response->json('data.completed_count'))
        ->toBe(2)
        ->and($completed['default']['id'])
        ->toBe($instances[0]->id)
        ->and($completed['default']['route']['domain'])
        ->toBe('primary.test')
        ->and($completed['feature']['id'])
        ->toBe($instances[1]->id)
        ->and($completed['feature']['route']['domain'])
        ->toBe('feature.acme.test')
        ->and(Route::query()->count())
        ->toBe(2)
        ->and($this->registrationSource->calls)
        ->not->toContain('inspect');
})->with([
    'secondary without include-worktrees' => [false, false],
    'secondary with include-worktrees' => [true, false],
    'new graph containing a retained secondary' => [true, true],
]);

it('accepts evidence-backed managed primary retries after completion and interruption', function (
    bool $includeWorktrees,
    bool $completed,
): void {
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $originalPaths = $includeWorktrees
        ? ['/work/acme', '/work/feature']
        : ['/work/acme'];
    $facts = $includeWorktrees
        ? registration_set_facts($originalPaths)
        : [registration_facts()];
    $destinations = $includeWorktrees
        ? ['/srv/orbit/apps/acme/default', '/srv/orbit/apps/acme/feature']
        : ['/srv/orbit/apps/acme/default'];
    $requestId = (string) Str::uuid();
    $routeIds = [];
    $instances = collect($facts)->map(function (RegistrationSourceFacts $fact, int $index) use (
        $project,
        $completed,
        $destinations,
        $includeWorktrees,
        $originalPaths,
        $requestId,
        &$routeIds,
    ): Instance {
        $instance = Instance::query()->create([
            'project_id' => $project->id,
            'node_id' => $this->node->id,
            'name' => $index === 0 ? 'default' : 'feature',
            'source_layout' => $fact->layout,
            'checkout_path' => $destinations[$index],
            'branch' => $fact->branch,
            'starting_commit' => $fact->commit,
            'selected_php_version' => '8.5',
            'source_is_laravel' => true,
            'provisioning_step' => 'active',
            'registration_original_path' => $fact->path,
            'registration_request_id' => $requestId,
            'registration_primary' => $index === 0,
            'registration_include_worktrees' => $includeWorktrees,
            'registration_repository_url' => $fact->repositoryUrl,
            'registration_repository_identity' => $fact->repositoryIdentity,
            'registration_source_digest' => $fact->sourceDigest,
            'registration_detached' => $fact->detached,
            'registration_default_branch' => $fact->defaultBranch,
            'registration_inferred_slug' => $fact->inferredSlug,
            'registration_inferred_root' => $fact->inferredRoot,
            'registration_common_repository_path' => $fact->commonRepositoryPath,
            'registration_worktree_paths' => $originalPaths,
            'registration_relocation_state' => 'relocated',
            'registration_authoritative_path' => $destinations[$index],
            'registration_completed_at' => $completed ? now() : null,
            'status' => InstanceState::Active,
        ]);
        $route = Route::query()->create([
            'project_id' => $project->id,
            'node_id' => $this->node->id,
            'generation_basis_node_id' => $index === 0 ? null : $this->node->id,
            'domain' => $index === 0 ? 'primary.test' : 'feature.acme.test',
            'provenance' => $index === 0 ? RouteProvenance::Explicit : RouteProvenance::Generated,
            'publication' => RoutePublication::Private,
            'status' => RouteStatus::Pending,
        ]);
        $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
        $route->update(['status' => RouteStatus::Active]);
        $routeIds[$instance->name] = $route->id;

        return $instance;
    });

    $response = $this->postJson('/api/v1/instances/register', [
        'source_path' => $destinations[0],
        'include_worktrees' => $includeWorktrees,
        'project_id' => $project->id,
        'domain' => 'primary.test',
    ])->assertOk();
    $retried = collect($response->json('data.instances'))->keyBy('name');
    $instanceIds = $instances->mapWithKeys(
        static fn (Instance $instance): array => [$instance->name => $instance->id],
    )->all();

    expect($response->json('data.source_count'))
        ->toBe(count($facts))
        ->and($response->json('data.completed_count'))
        ->toBe(count($facts))
        ->and(
            $retried->mapWithKeys(
                static fn (array $instance): array => [$instance['name'] => $instance['id']],
            )->all(),
        )
        ->toBe($instanceIds)
        ->and(
            $retried->mapWithKeys(
                static fn (array $instance): array => [$instance['name'] => $instance['route']['id']],
            )->all(),
        )
        ->toBe($routeIds)
        ->and(
            Instance::query()
                ->whereIn('id', $instances->pluck('id'))
                ->whereNotNull(
                    'registration_completed_at',
                )
                ->count(),
        )
        ->toBe(count($facts))
        ->and($this->registrationSource->calls)
        ->not->toContain('inspect', 'relocate-set:1', 'relocate-set:2');
})->with([
    'completed single source' => [false, true],
    'interrupted single source' => [false, false],
    'interrupted included source set' => [true, false],
]);
it('finishes the same published registration without downgrading its active provisioning state', function (): void {
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $this->node->id,
        'name' => 'default',
        'source_layout' => 'checkout',
        'checkout_path' => '/srv/orbit/apps/acme/default',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => '8.5',
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'registration_original_path' => '/work/acme',
        'registration_request_id' => (string) Str::uuid(),
        'registration_primary' => true,
        'registration_repository_url' => 'git@github.com:acme/acme.git',
        'registration_repository_identity' => 'github.com/acme/acme',
        'registration_source_digest' => str_repeat('c', 64),
        'registration_default_branch' => 'main',
        'registration_inferred_slug' => 'acme',
        'registration_inferred_root' => 'public',
        'registration_common_repository_path' => '/work/acme/.git',
        'registration_worktree_paths' => ['/work/acme'],
        'registration_relocation_state' => 'relocated',
        'registration_authoritative_path' => '/srv/orbit/apps/acme/default',
        'status' => InstanceState::Active,
    ]);
    $route = Route::query()->create([
        'project_id' => $project->id,
        'node_id' => $this->node->id,
        'domain' => 'preserved.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);

    $response = $this->postJson('/api/v1/instances/register', [
        'source_path' => '/work/acme',
        'project_id' => $project->id,
        'domain' => 'preserved.test',
    ])->assertOk();

    expect($response->json('data.instance.id'))
        ->toBe($instance->id)
        ->and($response->json('data.instance.route.id'))
        ->toBe($route->id)
        ->and($response->json('data.instance.route.domain'))
        ->toBe('preserved.test')
        ->and($instance
            ->refresh()
            ->only([
                'status',
                'provisioning_step',
                'selected_php_version',
                'source_is_laravel',
                'failed_step',
                'error_code',
            ]))
        ->toBe([
            'status' => InstanceState::Active,
            'provisioning_step' => 'active',
            'selected_php_version' => '8.5',
            'source_is_laravel' => true,
            'failed_step' => null,
            'error_code' => null,
        ])
        ->and($instance->registration_completed_at)
        ->not
        ->toBeNull()
        ->and($this->registrationSource->calls)
        ->toBe(['validate:/srv/orbit/apps/acme/default', 'url-discard']);
});

it('retries receipt cleanup after registration completion without republishing or replacing evidence', function (): void {
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $payload = ['source_path' => '/work/acme', 'project_id' => $project->id];
    $this->registrationSource->failDiscardOnce = true;

    $this
        ->postJson('/api/v1/instances/register', $payload)
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'instance.registration_incomplete');

    $instance = Instance::query()->sole();
    $route = Route::query()->sole();
    expect($instance->status)
        ->toBe(InstanceState::Active)
        ->and($instance->provisioning_step)
        ->toBe('active')
        ->and($instance->registration_completed_at)
        ->not
        ->toBeNull()
        ->and($instance->failed_step)
        ->toBe('registration')
        ->and($instance->error_code)
        ->toBe('instance.laravel_rollback_failed');

    $response = $this->postJson('/api/v1/instances/register', $payload)->assertOk();

    expect($response->json('data.instance.id'))
        ->toBe($instance->id)
        ->and($response->json('data.instance.route.id'))
        ->toBe($route->id)
        ->and($instance->refresh()->failed_step)
        ->toBeNull()
        ->and($instance->error_code)
        ->toBeNull()
        ->and(Instance::query()->count())
        ->toBe(1)
        ->and(Route::query()->count())
        ->toBe(1)
        ->and($this->registrationSource->calls)
        ->toBe([
            'inspect',
            'relocate-set:1',
            'url-prepare',
            'url-discard',
            'validate:/srv/orbit/apps/acme/default',
            'url-discard',
        ]);
});

it('recovers a same-filesystem move that outran its relocation checkpoint', function (): void {
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $this->node->id,
        'name' => 'default',
        'source_layout' => 'checkout',
        'checkout_path' => '/srv/orbit/apps/acme/default',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        ...registration_evidence_for_test('/work/acme'),
        'registration_relocation_state' => 'relocating',
        'registration_authoritative_path' => '/work/acme',
        'status' => InstanceState::Reserved,
    ]);
    $this->registrationSource->invalidRelocationPaths = ['/work/acme'];

    $response = $this->postJson('/api/v1/instances/register', [
        'source_path' => '/work/acme',
        'project_id' => $project->id,
    ])->assertOk();

    expect($response->json('data.instance.id'))
        ->toBe($instance->id)
        ->and($this->configuration->inspected)
        ->toBe([
            '/srv/orbit/apps/acme/default',
            '/srv/orbit/apps/acme/default',
        ])
        ->and($this->registrationSource->calls)
        ->toBe([
            'validate-relocation:/work/acme',
            'validate-relocation:/srv/orbit/apps/acme/default',
            'relocate-set:1',
            'url-prepare',
            'url-discard',
        ]);
});

it('refuses a future managed primary path before relocation makes it a candidate', function (): void {
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $original = '/work/acme';
    $destination = '/srv/orbit/apps/acme/default';
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $this->node->id,
        'name' => 'default',
        'source_layout' => 'checkout',
        'checkout_path' => $destination,
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        ...registration_evidence_for_test($original),
        'registration_relocation_state' => 'reserved',
        'registration_authoritative_path' => $original,
        'status' => InstanceState::Reserved,
    ]);
    $before = $instance->refresh()->getAttributes();

    $this
        ->postJson('/api/v1/instances/register', [
            'source_path' => $destination,
            'project_id' => $project->id,
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.registration_conflict');

    expect($instance->refresh()->getAttributes())
        ->toBe($before)
        ->and(Instance::query()->count())
        ->toBe(1)
        ->and(Route::query()->count())
        ->toBe(0)
        ->and($this->registrationSource->calls)
        ->toBe([]);
});
it('refuses to adopt an Instance already owned through instance new', function (): void {
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $this->node->id,
        'name' => 'default',
        'source_layout' => 'checkout',
        'checkout_path' => '/work/acme',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => '8.5',
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'status' => InstanceState::Active,
    ]);
    $before = $instance->refresh()->getAttributes();

    $this
        ->postJson('/api/v1/instances/register', [
            'source_path' => '/work/acme',
            'project_id' => $project->id,
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.source_conflict');

    expect($instance->refresh()->getAttributes())
        ->toBe($before)
        ->and(Route::query()->count())
        ->toBe(0)
        ->and($this->registrationSource->calls)
        ->toBe(['inspect']);
});

it('uses an explicit hostname only for the primary member of a requested source set', function (): void {
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $paths = ['/work/acme', '/work/feature'];
    $this->registrationSource->facts = registration_set_facts($paths);

    $response = $this->postJson('/api/v1/instances/register', [
        'source_path' => $paths[0],
        'include_worktrees' => true,
        'project_id' => $project->id,
        'domain' => 'primary.test',
    ])->assertOk();

    $instances = collect($response->json('data.instances'))->keyBy('name');
    $retry = $this->postJson('/api/v1/instances/register', [
        'source_path' => $paths[0],
        'include_worktrees' => true,
        'project_id' => $project->id,
        'domain' => 'primary.test',
    ])->assertOk();
    $retried = collect($retry->json('data.instances'))->keyBy('name');
    $retained = Instance::query()->get()->keyBy('name');
    expect($instances['default']['route']['domain'])
        ->toBe('primary.test')
        ->and($instances['feature']['route']['domain'])
        ->toBe('feature.acme.test')
        ->and($retried['default']['id'])
        ->toBe($instances['default']['id'])
        ->and($retried['default']['route']['id'])
        ->toBe($instances['default']['route']['id'])
        ->and($retried['feature']['id'])
        ->toBe($instances['feature']['id'])
        ->and($retried['feature']['route']['id'])
        ->toBe($instances['feature']['route']['id'])
        ->and($retained['default']->registration_route_domain)
        ->toBe('primary.test')
        ->and($retained['default']->registration_route_provenance)
        ->toBe(RouteProvenance::Explicit->value)
        ->and($retained['feature']->registration_route_domain)
        ->toBeNull()
        ->and($retained['feature']->registration_route_provenance)
        ->toBe(RouteProvenance::Generated->value)
        ->and(Route::query()->count())
        ->toBe(2);
});

it('adopts an unregistered source already at its calculated managed destination', function (): void {
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $path = '/srv/orbit/apps/acme/feature';
    $this->registrationSource->facts = registration_set_facts(['/work/acme', $path]);
    $this->registrationSource->facts = [$this->registrationSource->facts[1]];

    $this
        ->postJson('/api/v1/instances/register', [
            'source_path' => $path,
            'project_id' => $project->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.instance.checkout_path', $path);

    expect($this->destinationGuard->paths)->toBe([]);
});

it('preflights the requested and retained application root instead of unrelated repository metadata', function (string $root, string $relative, string $conflictingComposer, string $conflictingArtisan): void {
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $source = '/work/acme';
    $destination = '/srv/orbit/apps/acme/default';
    $metadata = [];
    foreach ([$source, $destination] as $checkout) {
        $metadata[$checkout] = ['composer' => $conflictingComposer, 'artisan' => $conflictingArtisan];
        $metadata[$checkout.'/server/web'] = ['composer' => $conflictingComposer, 'artisan' => $conflictingArtisan];
        $metadata[$checkout.$relative] = ['composer' => '{"require":{"php":"~8.4.0","laravel/framework":"^13.0"}}', 'artisan' => 'regular'];
    }
    $configuration = new class($metadata) implements DevelopmentInstanceConfigurator
    {
        /** @var list<string> */
        public array $inspected = [];

        /** @param array<string, array{composer: string, artisan: string}> $metadata */
        public function __construct(private readonly array $metadata) {}

        public function inspect(Instance $instance): DevelopmentSourceProfile
        {
            $directory = $instance->applicationDirectory();
            $this->inspected[] = $directory;
            $metadata = $this->metadata[$directory];

            return new ComposerSourceClassifier(new InstancePhpVersionCatalog)->classify(
                $metadata['composer'], $instance->project->type, $metadata['artisan'],
            );
        }

        public function configureLaravelUrl(Instance $instance, string $url): void {}
    };
    app()->instance(DevelopmentInstanceConfigurator::class, $configuration);
    $payload = ['source_path' => $source, 'project_id' => $project->id, 'root' => $root];

    $first = $this->postJson('/api/v1/instances/register', $payload)->assertOk();
    $omitted = $this->postJson('/api/v1/instances/register', [
        'source_path' => $source, 'project_id' => $project->id,
    ])->assertOk();
    $identical = $this->postJson('/api/v1/instances/register', $payload)->assertOk();
    $this->postJson('/api/v1/instances/register', [
        ...$payload, 'root' => $root === 'public' ? 'server/web/public' : 'public',
    ])->assertConflict()->assertJsonPath('error.code', 'instance.registration_conflict');

    expect($omitted->json('data.instance.id'))->toBe($first->json('data.instance.id'))
        ->and($identical->json('data.instance.id'))->toBe($first->json('data.instance.id'))
        ->and(array_values(array_unique($configuration->inspected)))->toBe([$source.$relative, $destination.$relative])
        ->and(Instance::query()->sole()->root)->toBe($root === 'public' ? null : $root)
        ->and(Instance::query()->sole()->selected_php_version)->toBe('8.4')
        ->and(Route::query()->count())->toBe(1);
})->with([
    'nested app with unsupported repository PHP' => ['server/web/public', '/server/web', '{"require":{"php":">8.5"}}', 'absent'],
    'nested app with partial repository Laravel' => ['server/web/public', '/server/web', '{"require":{"laravel/framework":"^13.0"}}', 'absent'],
    'root public ignores nested metadata' => ['public', '', '{"require":{"php":">8.5"}}', 'absent'],
]);

it('preflights every member source profile before reservation or relocation', function (): void {
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $paths = ['/work/acme', '/work/feature'];
    $this->registrationSource->facts = registration_set_facts($paths);
    $this->configuration->unsafePath = $paths[1];

    $this
        ->postJson('/api/v1/instances/register', [
            'source_path' => $paths[0],
            'include_worktrees' => true,
            'project_id' => $project->id,
        ])
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'app-dev.source_metadata_unsafe');

    expect($this->configuration->inspected)
        ->toBe($paths)
        ->and(Instance::query()->count())
        ->toBe(0)
        ->and($this->registrationSource->calls)
        ->toBe(['inspect']);
});

it('refuses retained source identity replacement before activation', function (): void {
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $payload = ['source_path' => '/work/acme', 'project_id' => $project->id];
    $this->projection->fail = true;
    $this->postJson('/api/v1/instances/register', $payload)->assertStatus(502);
    $this->registrationSource->retainedInvalid = true;

    $this
        ->postJson('/api/v1/instances/register', $payload)
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.registration_conflict');

    expect(Instance::query()->count())->toBe(1)->and(Route::query()->count())->toBe(1);
});

it('refuses a source nested in an Instance checkout', function (): void {
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $other = Project::query()->create([
        'name' => 'Other',
        'slug' => 'other',
        'repository_url' => 'https://github.com/acme/other.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $source = '/managed/other/nested/acme';
    Instance::query()->create([
        'project_id' => $other->id,
        'node_id' => $this->node->id,
        'name' => 'default',
        'environment' => 'development',
        'checkout_path' => '/managed/other',
        'status' => InstanceState::Active,
    ]);

    $facts = registration_facts();
    $this->registrationSource->facts = [new RegistrationSourceFacts(
        path: $source,
        layout: $facts->layout,
        repositoryUrl: $facts->repositoryUrl,
        repositoryIdentity: $facts->repositoryIdentity,
        branch: $facts->branch,
        detached: $facts->detached,
        commit: $facts->commit,
        defaultBranch: $facts->defaultBranch,
        inferredSlug: $facts->inferredSlug,
        inferredRoot: $facts->inferredRoot,
        commonRepositoryPath: $source.'/.git',
        worktreePaths: [$source],
        sourceDigest: $facts->sourceDigest,
    )];

    $this
        ->postJson('/api/v1/instances/register', [
            'source_path' => $source,
            'project_id' => $project->id,
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.source_conflict');

    expect($this->registrationSource->calls)->toBe(['inspect']);
});

function bind_route_domain_update_for_registration_test(): void
{
    $projector = Mockery::mock(RouteDomainProjector::class);
    $projector->shouldReceive([
        'prepareWorkloadCertificate' => null,
        'prepareWorkloadCaddy' => null,
        'prepareRouterCertificate' => null,
        'prepareFirewallPolicy' => null,
        'verifyWorkload' => null,
        'prepareRouterCaddy' => null,
        'publishDns' => null,
        'prepareCleanup' => null,
        'cleanup' => null,
    ]);
    app()->instance(RouteDomainProjector::class, $projector);
    app()->instance(DevelopmentProjectionOperationLock::class, new class implements DevelopmentProjectionOperationLock
    {
        public function run(Closure $operation): mixed
        {
            return $operation();
        }
    });
}

function registration_facts(string $digest = '', string $path = '/work/acme'): RegistrationSourceFacts
{
    return new RegistrationSourceFacts(
        path: $path,
        layout: InstanceSourceLayout::Checkout,
        repositoryUrl: 'git@github.com:acme/acme.git',
        repositoryIdentity: 'github.com/acme/acme',
        branch: 'main',
        detached: false,
        commit: str_repeat('a', 40),
        defaultBranch: 'main',
        inferredSlug: 'acme',
        inferredRoot: 'public',
        commonRepositoryPath: $path.'/.git',
        worktreePaths: [$path],
        sourceDigest: $digest === '' ? str_repeat('c', 64) : $digest,
    );
}

/** @return array<string, mixed> */
function registration_evidence_for_test(string $source): array
{
    return [
        'registration_original_path' => $source,
        'registration_request_id' => (string) Str::uuid(),
        'registration_primary' => true,
        'registration_include_worktrees' => false,
        'registration_repository_url' => 'git@github.com:acme/acme.git',
        'registration_repository_identity' => 'github.com/acme/acme',
        'registration_source_digest' => str_repeat('c', 64),
        'registration_detached' => false,
        'registration_default_branch' => 'main',
        'registration_inferred_slug' => 'acme',
        'registration_inferred_root' => 'public',
        'registration_common_repository_path' => $source.'/.git',
        'registration_worktree_paths' => [$source],
        'registration_route_domain' => null,
        'registration_route_provenance' => RouteProvenance::Generated->value,
    ];
}

/** @return array{app_instance: array<string, mixed>, route: array{id: int, domain: string, provenance: string}} */
function registration_set_facts(array $paths): array
{
    return [
        new RegistrationSourceFacts(
            path: $paths[0],
            layout: InstanceSourceLayout::Checkout,
            repositoryUrl: 'git@github.com:acme/acme.git',
            repositoryIdentity: 'github.com/acme/acme',
            branch: 'main',
            detached: false,
            commit: str_repeat('a', 40),
            defaultBranch: 'main',
            inferredSlug: 'acme',
            inferredRoot: 'public',
            commonRepositoryPath: $paths[0].'/.git',
            worktreePaths: $paths,
            sourceDigest: str_repeat('c', 64),
        ),
        new RegistrationSourceFacts(
            path: $paths[1],
            layout: InstanceSourceLayout::Worktree,
            repositoryUrl: 'git@github.com:acme/acme.git',
            repositoryIdentity: 'github.com/acme/acme',
            branch: 'feature',
            detached: false,
            commit: str_repeat('b', 40),
            defaultBranch: 'main',
            inferredSlug: 'acme',
            inferredRoot: 'public',
            commonRepositoryPath: $paths[0].'/.git',
            worktreePaths: $paths,
            sourceDigest: str_repeat('d', 64),
        ),
    ];
}
