<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceDestinationGuard;
use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\DevelopmentAppInstanceConfigurator;
use App\Domain\AppInstances\DevelopmentRouteProjector;
use App\Domain\AppInstances\DevelopmentSourceProfile;
use App\Domain\AppInstances\Registration\RegistrationSourceFacts;
use App\Domain\AppInstances\Registration\RegistrationSourceManager;
use App\Domain\Instances\CertificateMode;
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
use App\Models\Instance;
use App\Models\Node;
use App\Models\Route;
use App\Models\Workspace;

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
    $this->destinationGuard = new class implements AppInstanceDestinationGuard {
        /** @var list<string> */
        public array $paths = [];

        public function assertUnoccupied(Node $node, App\Domain\Nodes\Storage\StoragePath $destination): void
        {
            $this->paths[] = $destination->value;
        }
    };
    app()->instance(AppInstanceDestinationGuard::class, $this->destinationGuard);
    app()->instance(RepositoryDefaultBranchResolver::class, new class implements RepositoryDefaultBranchResolver {
        public function resolve(string $repository): string
        {
            return 'main';
        }

        public function verify(string $repository, string $branch): void {}
    });
    $this->configuration = new class implements DevelopmentAppInstanceConfigurator {
        public ?string $unsafePath = null;

        /** @var list<string> */
        public array $inspected = [];

        public function inspect(AppInstance $appInstance): DevelopmentSourceProfile
        {
            $this->inspected[] = $appInstance->checkout_path;

            if ($appInstance->checkout_path === $this->unsafePath) {
                throw new App\Domain\AppDev\RuntimeConvergenceException(
                    'source-classification',
                    'app-dev.source_metadata_unsafe',
                    'The development source metadata is invalid or unsupported.',
                );
            }

            return new DevelopmentSourceProfile('8.5', true);
        }

        public function configureLaravelUrl(AppInstance $appInstance, string $url): void {}
    };
    app()->instance(DevelopmentAppInstanceConfigurator::class, $this->configuration);
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

        public function relocate(AppInstance $appInstance, RegistrationSourceFacts $facts): void
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
                AppInstance::query()
                    ->whereKey($member['appInstance']->id)
                    ->update([
                        'registration_relocation_state' => 'relocated',
                        'registration_authoritative_path' => $member['appInstance']->checkout_path,
                    ]);
            }
        }

        public function restoreOriginal(AppInstance $appInstance, RegistrationSourceFacts $facts): void
        {
            $this->calls[] = 'restore-original';
            AppInstance::query()
                ->whereKey($appInstance->id)
                ->update([
                    'registration_relocation_state' => 'reserved',
                    'registration_authoritative_path' => $facts->path,
                ]);
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

    $this
        ->postJson('/api/v1/instances/register', [...$payload, 'app_slug' => 'different'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'app.identity_conflict');
    expect(AppInstance::query()->count())->toBe(1)->and(Route::query()->count())->toBe(1);
});

it('preserves an ordinary retained root when retry input is omitted or identical and returns 409 for a conflict', function (): void {
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $payload = [
        'source_path' => '/work/acme',
        'app_id' => $app->id,
        'root' => 'web',
    ];

    $first = $this->postJson('/api/v1/instances/register', $payload)->assertOk();
    $omitted = $this->postJson('/api/v1/instances/register', [
        'source_path' => '/work/acme',
        'app_id' => $app->id,
    ])->assertOk();
    $identical = $this->postJson('/api/v1/instances/register', $payload)->assertOk();
    $instance = AppInstance::query()->sole();
    $route = Route::query()->sole();
    $before = $instance->refresh()->getAttributes();

    $this
        ->postJson('/api/v1/instances/register', [
            'source_path' => '/work/acme',
            'app_id' => $app->id,
            'root' => 'public',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.registration_conflict');

    expect($omitted->json('data.app_instance.id'))
        ->toBe($first->json('data.app_instance.id'))
        ->and($identical->json('data.app_instance.id'))
        ->toBe($first->json('data.app_instance.id'))
        ->and($instance->refresh()->getAttributes())
        ->toBe($before)
        ->and($instance->root)
        ->toBe('web')
        ->and($route->id)
        ->toBe($first->json('data.app_instance.route.id'));
});

it('returns 409 before mutation when the App root conflicts with retained migration intent', function (): void {
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $source = '/work/legacy-main-source';
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $this->node->id,
        'name' => 'main',
        'source_layout' => 'checkout',
        'checkout_path' => $source,
        'root' => 'web',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => '8.4',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
        'migration_required' => true,
        ...registration_evidence_for_test($source),
        'registration_route_hostname' => 'preserved.test',
        'registration_route_provenance' => RouteProvenance::Explicit->value,
        'registration_relocation_state' => 'reserved',
        'registration_authoritative_path' => $source,
        'status' => AppInstanceState::Active,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $this->node->id,
        'hostname' => 'preserved.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $instance->update([
        'registration_migration_recovery' => [
            ...registration_migration_recovery($instance, $route),
            'planned' => [
                'name' => 'default',
                'checkout_path' => '/srv/orbit/apps/acme/default',
            ],
        ],
    ]);
    $before = $instance->refresh()->getAttributes();

    $this
        ->postJson('/api/v1/instances/register', [
            'source_path' => $source,
            'app_id' => $app->id,
            'root' => 'public',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.registration_conflict');

    expect($instance->refresh()->getAttributes())
        ->toBe($before)
        ->and($instance->root)
        ->toBe('web')
        ->and($route->refresh()->hostname)
        ->toBe('preserved.test');
});

it('retains explicit hostname intent before Route creation and rejects a changed retry with 409', function (): void {
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $payload = [
        'source_path' => '/work/acme',
        'app_id' => $app->id,
        'hostname' => 'original.test',
    ];
    $this->registrationSource->failRelocateOnce = true;

    $this
        ->postJson('/api/v1/instances/register', $payload)
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'instance.registration_incomplete');

    $instance = AppInstance::query()->sole();
    $before = $instance->refresh()->getAttributes();

    $this
        ->postJson('/api/v1/instances/register', [
            'source_path' => '/work/acme',
            'app_id' => $app->id,
            'hostname' => 'changed.test',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.registration_conflict');

    expect($instance->refresh()->getAttributes())
        ->toBe($before)
        ->and($instance->registration_route_hostname)
        ->toBe('original.test')
        ->and($instance->registration_route_provenance)
        ->toBe(RouteProvenance::Explicit->value)
        ->and(Route::query()->count())
        ->toBe(0);

    $identical = $this->postJson('/api/v1/instances/register', $payload)->assertOk();
    $omitted = $this->postJson('/api/v1/instances/register', [
        'source_path' => '/work/acme',
        'app_id' => $app->id,
    ])->assertOk();

    expect($identical->json('data.app_instance.route.hostname'))
        ->toBe('original.test')
        ->and($omitted->json('data.app_instance.route.id'))
        ->toBe($identical->json('data.app_instance.route.id'))
        ->and($omitted->json('data.app_instance.route.hostname'))
        ->toBe('original.test');
});

it('preserves explicit hostname intent after Route creation and returns 409 for a changed retry', function (): void {
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $payload = [
        'source_path' => '/work/acme',
        'app_id' => $app->id,
        'hostname' => 'preserved.test',
    ];
    $this->projection->fail = true;

    $this
        ->postJson('/api/v1/instances/register', $payload)
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'instance.registration_incomplete');

    $instance = AppInstance::query()->sole();
    $route = Route::query()->sole();
    $instanceBefore = $instance->refresh()->getAttributes();
    $routeBefore = $route->refresh()->getAttributes();

    $this
        ->postJson('/api/v1/instances/register', [
            'source_path' => '/work/acme',
            'app_id' => $app->id,
            'hostname' => 'changed.test',
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
        'app_id' => $app->id,
    ])->assertOk();
    $identical = $this->postJson('/api/v1/instances/register', $payload)->assertOk();

    expect($omitted->json('data.app_instance.route.id'))
        ->toBe($route->id)
        ->and($omitted->json('data.app_instance.route.hostname'))
        ->toBe('preserved.test')
        ->and($identical->json('data.app_instance.route.id'))
        ->toBe($route->id);
});

it('retains generated hostname provenance and returns 409 for a later explicit hostname', function (): void {
    $app = OrbitApp::query()->create([
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
            'app_id' => $app->id,
        ])
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'instance.registration_incomplete');

    $instance = AppInstance::query()->sole();
    $before = $instance->refresh()->getAttributes();

    $this
        ->postJson('/api/v1/instances/register', [
            'source_path' => '/work/acme',
            'app_id' => $app->id,
            'hostname' => 'changed.test',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.registration_conflict');

    expect($instance->refresh()->getAttributes())
        ->toBe($before)
        ->and($instance->registration_route_hostname)
        ->toBeNull()
        ->and($instance->registration_route_provenance)
        ->toBe(RouteProvenance::Generated->value)
        ->and(Route::query()->count())
        ->toBe(0);

    $response = $this->postJson('/api/v1/instances/register', [
        'source_path' => '/work/acme',
        'app_id' => $app->id,
    ])->assertOk();

    expect($response->json('data.app_instance.route.provenance'))
        ->toBe(RouteProvenance::Generated->value);
});

it('refuses colliding complete-set identities before reservation on every retry', function (
    array $paths,
    ?string $name,
): void {
    $app = OrbitApp::query()->create([
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
        'app_id' => $app->id,
        ...($name === null ? [] : ['instance_name' => $name]),
    ];

    foreach ([1, 2] as $attempt) {
        $this
            ->postJson('/api/v1/instances/register', $payload)
            ->assertConflict()
            ->assertJsonPath('error.code', 'instance.identity_conflict');

        expect(AppInstance::query()->count())
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
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $paths = ['/work/primary/source', '/work/linked/feature'];
    $requestId = (string) Illuminate\Support\Str::uuid();
    AppInstance::query()->create([
        'app_id' => $app->id,
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
                'app_id' => $app->id,
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'instance.registration_evidence_invalid');

        expect(AppInstance::query()->count())
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
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $paths = ['/work/acme', '/work/feature'];
    $facts = registration_set_facts($paths);
    $requestId = (string) Illuminate\Support\Str::uuid();
    $instances = collect($facts)->map(function (RegistrationSourceFacts $fact, int $index) use (
        $app,
        $requestId,
    ): AppInstance {
        return AppInstance::query()->create([
            'app_id' => $app->id,
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
            'registration_route_hostname' => $index === 0 ? 'primary.test' : null,
            'registration_route_provenance' => $index === 0
                ? RouteProvenance::Explicit->value
                : RouteProvenance::Generated->value,
            'status' => AppInstanceState::Reserved,
        ]);
    });
    $before = AppInstance::query()
        ->orderBy('id')
        ->get()
        ->mapWithKeys(
            static fn (AppInstance $instance): array => [$instance->id => $instance->getAttributes()],
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
            'app_id' => $app->id,
            'instance_name' => 'intruder',
            'hostname' => 'intruder.test',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.registration_conflict');

    expect(
        AppInstance::query()
            ->orderBy('id')
            ->get()
            ->mapWithKeys(
                static fn (AppInstance $instance): array => [$instance->id => $instance->getAttributes()],
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
        'app_id' => $app->id,
        'instance_name' => 'default',
        'hostname' => 'primary.test',
    ])->assertOk();
    $completed = collect($response->json('data.app_instances'))->keyBy('name');

    expect($response->json('data.source_count'))
        ->toBe(2)
        ->and($response->json('data.completed_count'))
        ->toBe(2)
        ->and($completed['default']['id'])
        ->toBe($instances[0]->id)
        ->and($completed['default']['route']['hostname'])
        ->toBe('primary.test')
        ->and($completed['feature']['id'])
        ->toBe($instances[1]->id)
        ->and($completed['feature']['route']['hostname'])
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
    $app = OrbitApp::query()->create([
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
    $requestId = (string) Illuminate\Support\Str::uuid();
    $routeIds = [];
    $instances = collect($facts)->map(function (RegistrationSourceFacts $fact, int $index) use (
        $app,
        $completed,
        $destinations,
        $includeWorktrees,
        $originalPaths,
        $requestId,
        &$routeIds,
    ): AppInstance {
        $instance = AppInstance::query()->create([
            'app_id' => $app->id,
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
            'status' => AppInstanceState::Active,
        ]);
        $route = Route::query()->create([
            'app_id' => $app->id,
            'node_id' => $this->node->id,
            'generation_basis_node_id' => $index === 0 ? null : $this->node->id,
            'hostname' => $index === 0 ? 'primary.test' : 'feature.acme.test',
            'provenance' => $index === 0 ? RouteProvenance::Explicit : RouteProvenance::Generated,
            'publication' => RoutePublication::Private,
            'status' => RouteStatus::Pending,
        ]);
        $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
        $route->update(['status' => RouteStatus::Active]);
        $routeIds[$instance->name] = $route->id;

        return $instance;
    });

    $response = $this->postJson('/api/v1/instances/register', [
        'source_path' => $destinations[0],
        'include_worktrees' => $includeWorktrees,
        'app_id' => $app->id,
        'hostname' => 'primary.test',
    ])->assertOk();
    $retried = collect($response->json('data.app_instances'))->keyBy('name');
    $instanceIds = $instances->mapWithKeys(
        static fn (AppInstance $instance): array => [$instance->name => $instance->id],
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
            AppInstance::query()
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
        'provisioning_step' => 'active',
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
        'provisioning_step',
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
            'validate:/work/acme',
            'relocate-set:1',
            'url-prepare',
            'url-discard',
        ]);
});

it('resumes a manual migration from the durable post-transition boundary', function (): void {
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
        'name' => 'default',
        'source_layout' => 'checkout',
        'checkout_path' => '/srv/orbit/apps/acme/default',
        'branch' => 'main',
        'migration_required' => false,
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => '8.4',
        'source_is_laravel' => false,
        'registration_original_path' => '/work/acme',
        'registration_request_id' => (string) Illuminate\Support\Str::uuid(),
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
        'status' => AppInstanceState::SourceResolved,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $this->node->id,
        'generation_basis_node_id' => null,
        'hostname' => 'preserved.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $instance->update([
        'registration_migration_recovery' => [
            'app_instance' => [
                'name' => 'main',
                'source_layout' => 'checkout',
                'checkout_path' => '/work/acme',
                'root' => null,
                'branch' => 'main',
                'branch_override' => null,
                'migration_required' => 1,
                'starting_commit' => str_repeat('a', 40),
                'selected_php_version' => '8.4',
                'source_is_laravel' => 0,
                'provisioning_step' => 'active',
                'status' => AppInstanceState::Active->value,
            ],
            'route' => [
                'id' => $route->id,
                'hostname' => 'preserved.test',
                'provenance' => RouteProvenance::Explicit->value,
            ],
        ],
    ]);

    $response = $this->postJson('/api/v1/instances/register', [
        'source_path' => '/work/acme',
        'app_id' => $app->id,
    ])->assertOk();

    expect($response->json('data.app_instance.id'))
        ->toBe($instance->id)
        ->and($response->json('data.app_instance.route.id'))
        ->toBe($route->id)
        ->and($response->json('data.app_instance.route.hostname'))
        ->toBe('preserved.test')
        ->and($instance->refresh()->registration_migration_recovery)
        ->toBeNull()
        ->and($instance->migration_required)
        ->toBeFalse()
        ->and($this->registrationSource->calls)
        ->toBe([
            'validate:/srv/orbit/apps/acme/default',
            'url-prepare',
            'url-discard',
        ]);
});

it('finishes the same published registration without downgrading its active provisioning state', function (
    bool $manualMigration,
): void {
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
        'name' => 'default',
        'source_layout' => 'checkout',
        'checkout_path' => '/srv/orbit/apps/acme/default',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => '8.5',
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'registration_original_path' => '/work/acme',
        'registration_request_id' => (string) Illuminate\Support\Str::uuid(),
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
        'status' => AppInstanceState::Active,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $this->node->id,
        'hostname' => 'preserved.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);

    if ($manualMigration) {
        $instance->update([
            'registration_migration_recovery' => registration_migration_recovery($instance, $route),
        ]);
    }

    $response = $this->postJson('/api/v1/instances/register', [
        'source_path' => '/work/acme',
        'app_id' => $app->id,
        'hostname' => 'preserved.test',
    ])->assertOk();

    expect($response->json('data.app_instance.id'))
        ->toBe($instance->id)
        ->and($response->json('data.app_instance.route.id'))
        ->toBe($route->id)
        ->and($response->json('data.app_instance.route.hostname'))
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
            'status' => AppInstanceState::Active,
            'provisioning_step' => 'active',
            'selected_php_version' => '8.5',
            'source_is_laravel' => true,
            'failed_step' => null,
            'error_code' => null,
        ])
        ->and($instance->registration_completed_at)
        ->not
        ->toBeNull()
        ->and($instance->registration_migration_recovery)
        ->toBeNull()
        ->and($this->registrationSource->calls)
        ->toBe(['validate:/srv/orbit/apps/acme/default', 'url-discard']);
})->with([
    'ordinary registration' => false,
    'manual migration' => true,
]);

it('retries receipt cleanup after registration completion without republishing or replacing evidence', function (): void {
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $payload = ['source_path' => '/work/acme', 'app_id' => $app->id];
    $this->registrationSource->failDiscardOnce = true;

    $this
        ->postJson('/api/v1/instances/register', $payload)
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'instance.registration_incomplete');

    $instance = AppInstance::query()->sole();
    $route = Route::query()->sole();
    expect($instance->status)
        ->toBe(AppInstanceState::Active)
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

    expect($response->json('data.app_instance.id'))
        ->toBe($instance->id)
        ->and($response->json('data.app_instance.route.id'))
        ->toBe($route->id)
        ->and($instance->refresh()->failed_step)
        ->toBeNull()
        ->and($instance->error_code)
        ->toBeNull()
        ->and(AppInstance::query()->count())
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
        'name' => 'default',
        'source_layout' => 'checkout',
        'checkout_path' => '/srv/orbit/apps/acme/default',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        ...registration_evidence_for_test('/work/acme'),
        'registration_relocation_state' => 'relocating',
        'registration_authoritative_path' => '/work/acme',
        'status' => AppInstanceState::Reserved,
    ]);
    $this->registrationSource->invalidRelocationPaths = ['/work/acme'];

    $response = $this->postJson('/api/v1/instances/register', [
        'source_path' => '/work/acme',
        'app_id' => $app->id,
    ])->assertOk();

    expect($response->json('data.app_instance.id'))
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

it('refuses a future managed primary path before relocation makes it a candidate', function (
    bool $migration,
): void {
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $original = '/work/acme';
    $destination = '/srv/orbit/apps/acme/default';
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $this->node->id,
        'name' => $migration ? 'main' : 'default',
        'source_layout' => 'checkout',
        'checkout_path' => $migration ? $original : $destination,
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'migration_required' => $migration,
        ...registration_evidence_for_test($original),
        'registration_relocation_state' => 'reserved',
        'registration_authoritative_path' => $original,
        'status' => $migration ? AppInstanceState::Active : AppInstanceState::Reserved,
    ]);
    $before = $instance->refresh()->getAttributes();

    $this
        ->postJson('/api/v1/instances/register', [
            'source_path' => $destination,
            'app_id' => $app->id,
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.registration_conflict');

    expect($instance->refresh()->getAttributes())
        ->toBe($before)
        ->and(AppInstance::query()->count())
        ->toBe(1)
        ->and(Route::query()->count())
        ->toBe(0)
        ->and($this->registrationSource->calls)
        ->toBe([]);
})->with([
    'ordinary registration' => false,
    'manual migration' => true,
]);

it('resumes an interrupted manual migration through its validated planned destination', function (
    string $state,
): void {
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $original = '/work/acme';
    $destination = '/srv/orbit/apps/acme/default';
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $this->node->id,
        'name' => 'main',
        'source_layout' => 'checkout',
        'checkout_path' => $original,
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => '8.4',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
        'migration_required' => true,
        ...registration_evidence_for_test($original),
        'registration_relocation_state' => $state,
        'registration_authoritative_path' => $state === 'relocating' ? $original : $destination,
        'status' => AppInstanceState::Active,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $this->node->id,
        'hostname' => 'preserved.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $instance->update([
        'registration_migration_recovery' => [
            ...registration_migration_recovery($instance, $route),
            'planned' => [
                'name' => 'default',
                'checkout_path' => $destination,
            ],
        ],
    ]);

    $response = $this->postJson('/api/v1/instances/register', [
        'source_path' => $destination,
    ])->assertOk();

    expect($response->json('data.app_instance.id'))
        ->toBe($instance->id)
        ->and($response->json('data.app_instance.route.id'))
        ->toBe($route->id)
        ->and($response->json('data.app_instance.checkout_path'))
        ->toBe($destination)
        ->and(AppInstance::query()->count())
        ->toBe(1)
        ->and(Route::query()->count())
        ->toBe(1)
        ->and($this->registrationSource->calls)
        ->toContain('inspect');

    if ($state === 'relocating') {
        expect($this->registrationSource->calls)
            ->toContain('validate-relocation:'.$destination);
    } else {
        expect($this->registrationSource->calls)
            ->toContain('validate:'.$destination);
    }

    $instance->refresh()->update(['registration_completed_at' => null]);
    $retry = $this->postJson('/api/v1/instances/register', [
        'source_path' => $destination,
    ])->assertOk();

    expect($retry->json('data.app_instance.id'))
        ->toBe($instance->id)
        ->and($retry->json('data.app_instance.name'))
        ->toBe('default')
        ->and($retry->json('data.app_instance.checkout_path'))
        ->toBe($destination)
        ->and($retry->json('data.app_instance.route.id'))
        ->toBe($route->id)
        ->and($instance->refresh()->registration_completed_at)
        ->not->toBeNull();
})->with([
    'rename before checkpoint' => 'relocating',
    'verified destination' => 'destination_verified',
    'original cleanup' => 'original_cleanup',
    'relocated before publication' => 'relocated',
]);

it('migrates a marked default source independently of its original directory name', function (): void {
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $source = '/work/legacy-main-source';
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $this->node->id,
        'name' => 'main',
        'source_layout' => 'checkout',
        'checkout_path' => $source,
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => '8.4',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
        'migration_required' => true,
        'status' => AppInstanceState::Active,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $this->node->id,
        'hostname' => 'preserved.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $base = registration_facts();
    $this->registrationSource->facts = [new RegistrationSourceFacts(
        path: $source,
        layout: $base->layout,
        repositoryUrl: $base->repositoryUrl,
        repositoryIdentity: $base->repositoryIdentity,
        branch: $base->branch,
        detached: $base->detached,
        commit: $base->commit,
        defaultBranch: $base->defaultBranch,
        inferredSlug: $base->inferredSlug,
        inferredRoot: $base->inferredRoot,
        commonRepositoryPath: "{$source}/.git",
        worktreePaths: [$source],
        sourceDigest: $base->sourceDigest,
    )];

    $response = $this->postJson('/api/v1/instances/register', [
        'source_path' => $source,
        'app_id' => $app->id,
    ])->assertOk();

    expect($response->json('data.app_instance.id'))
        ->toBe($instance->id)
        ->and($response->json('data.app_instance.route.id'))
        ->toBe($route->id)
        ->and($response->json('data.app_instance.name'))
        ->toBe('default')
        ->and($response->json('data.app_instance.checkout_path'))
        ->toBe('/srv/orbit/apps/acme/default');
});

it('returns 409 when retained default migration input conflicts with planned intent', function (
    array $input,
    array $expectedCalls,
): void {
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $source = '/work/acme';
    $destination = '/srv/orbit/apps/acme/default';
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $this->node->id,
        'name' => 'main',
        'source_layout' => 'checkout',
        'checkout_path' => $source,
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => '8.4',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
        'migration_required' => true,
        ...registration_evidence_for_test($source),
        'registration_relocation_state' => 'reserved',
        'registration_authoritative_path' => $source,
        'status' => AppInstanceState::Active,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $this->node->id,
        'hostname' => 'preserved.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $instance->update([
        'registration_migration_recovery' => [
            ...registration_migration_recovery($instance, $route),
            'planned' => ['name' => 'default', 'checkout_path' => $destination],
        ],
    ]);
    $instanceBefore = $instance->refresh()->getAttributes();
    $routeBefore = $route->refresh()->getAttributes();

    $this
        ->postJson('/api/v1/instances/register', [
            'source_path' => $source,
            'app_id' => $app->id,
            ...$input,
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.registration_conflict');

    expect($instance->refresh()->getAttributes())
        ->toBe($instanceBefore)
        ->and($route->refresh()->getAttributes())
        ->toBe($routeBefore)
        ->and(AppInstance::query()->count())
        ->toBe(1)
        ->and(Route::query()->count())
        ->toBe(1)
        ->and($this->registrationSource->calls)
        ->toBe($expectedCalls);
})->with([
    'different instance name' => [['instance_name' => 'other'], ['validate:/work/acme']],
    'different explicit hostname' => [['hostname' => 'changed.test'], ['validate:/work/acme']],
    'different source-set intent' => [['include_worktrees' => true], []],
]);

it('returns 409 when several retained migrations match one inspected repository and destination', function (): void {
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $destination = '/srv/orbit/apps/acme/default';

    foreach ([
        ['main', '/work/acme-one'],
        ['13.x', '/work/acme-two'],
    ] as [$name, $source]) {
        $instance = AppInstance::query()->create([
            'app_id' => $app->id,
            'node_id' => $this->node->id,
            'name' => $name,
            'source_layout' => 'checkout',
            'checkout_path' => $source,
            'branch' => 'main',
            'starting_commit' => str_repeat('a', 40),
            'selected_php_version' => '8.4',
            'source_is_laravel' => false,
            'provisioning_step' => 'active',
            'migration_required' => true,
            ...registration_evidence_for_test($source),
            'registration_relocation_state' => 'relocating',
            'registration_authoritative_path' => $source,
            'status' => AppInstanceState::Active,
        ]);
        $route = Route::query()->create([
            'app_id' => $app->id,
            'node_id' => $this->node->id,
            'hostname' => "{$name}.test",
            'provenance' => RouteProvenance::Explicit,
            'publication' => RoutePublication::Private,
            'status' => RouteStatus::Pending,
        ]);
        $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
        $route->update(['status' => RouteStatus::Active]);
        $instance->update([
            'registration_migration_recovery' => [
                ...registration_migration_recovery($instance, $route),
                'planned' => ['name' => 'default', 'checkout_path' => $destination],
            ],
        ]);
    }
    $before = AppInstance::query()
        ->orderBy('id')
        ->get()
        ->mapWithKeys(static fn (AppInstance $instance): array => [$instance->id => $instance->getAttributes()])
        ->all();

    $this
        ->postJson('/api/v1/instances/register', ['source_path' => $destination])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.registration_evidence_invalid');

    expect(
        AppInstance::query()
            ->orderBy('id')
            ->get()
            ->mapWithKeys(static fn (AppInstance $instance): array => [$instance->id => $instance->getAttributes()])
            ->all(),
    )
        ->toBe($before)
        ->and(AppInstance::query()->count())
        ->toBe(2)
        ->and(Route::query()->count())
        ->toBe(2)
        ->and($this->registrationSource->calls)
        ->toBe(['inspect']);
});

it('refuses mismatched migration evidence at every managed recovery boundary without mutation', function (
    string $state,
): void {
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $original = '/work/acme';
    $destination = '/srv/orbit/apps/acme/default';
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $this->node->id,
        'name' => 'main',
        'source_layout' => 'checkout',
        'checkout_path' => $original,
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => '8.4',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
        'migration_required' => true,
        ...registration_evidence_for_test($original),
        'registration_relocation_state' => $state,
        'registration_authoritative_path' => $state === 'relocating' ? $original : $destination,
        'status' => AppInstanceState::Active,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $this->node->id,
        'hostname' => 'preserved.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $instance->update([
        'registration_migration_recovery' => [
            ...registration_migration_recovery($instance, $route),
            'planned' => [
                'name' => 'default',
                'checkout_path' => $destination,
            ],
        ],
    ]);
    $instanceBefore = $instance->refresh()->getAttributes();
    $routeBefore = $route->refresh()->getAttributes();
    $this->registrationSource->retainedInvalid = true;

    $this
        ->postJson('/api/v1/instances/register', [
            'source_path' => $destination,
            'app_id' => $app->id,
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.registration_conflict');

    expect($instance->refresh()->getAttributes())
        ->toBe($instanceBefore)
        ->and($route->refresh()->getAttributes())
        ->toBe($routeBefore)
        ->and(AppInstance::query()->count())
        ->toBe(1)
        ->and(Route::query()->count())
        ->toBe(1)
        ->and($this->registrationSource->calls)
        ->not->toContain('inspect', 'relocate-set:1');
})->with([
    'rename before checkpoint' => 'relocating',
    'verified destination' => 'destination_verified',
    'original cleanup' => 'original_cleanup',
    'relocated before publication' => 'relocated',
]);

it('refuses to adopt an AppInstance already owned through instance new', function (): void {
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
        'name' => 'default',
        'source_layout' => 'checkout',
        'checkout_path' => '/work/acme',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => '8.5',
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'status' => AppInstanceState::Active,
    ]);
    $before = $instance->refresh()->getAttributes();

    $this
        ->postJson('/api/v1/instances/register', [
            'source_path' => '/work/acme',
            'app_id' => $app->id,
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
    $app = OrbitApp::query()->create([
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
        'app_id' => $app->id,
        'hostname' => 'primary.test',
    ])->assertOk();

    $instances = collect($response->json('data.app_instances'))->keyBy('name');
    $retry = $this->postJson('/api/v1/instances/register', [
        'source_path' => $paths[0],
        'include_worktrees' => true,
        'app_id' => $app->id,
        'hostname' => 'primary.test',
    ])->assertOk();
    $retried = collect($retry->json('data.app_instances'))->keyBy('name');
    $retained = AppInstance::query()->get()->keyBy('name');
    expect($instances['default']['route']['hostname'])
        ->toBe('primary.test')
        ->and($instances['feature']['route']['hostname'])
        ->toBe('feature.acme.test')
        ->and($retried['default']['id'])
        ->toBe($instances['default']['id'])
        ->and($retried['default']['route']['id'])
        ->toBe($instances['default']['route']['id'])
        ->and($retried['feature']['id'])
        ->toBe($instances['feature']['id'])
        ->and($retried['feature']['route']['id'])
        ->toBe($instances['feature']['route']['id'])
        ->and($retained['default']->registration_route_hostname)
        ->toBe('primary.test')
        ->and($retained['default']->registration_route_provenance)
        ->toBe(RouteProvenance::Explicit->value)
        ->and($retained['feature']->registration_route_hostname)
        ->toBeNull()
        ->and($retained['feature']->registration_route_provenance)
        ->toBe(RouteProvenance::Generated->value)
        ->and(Route::query()->count())
        ->toBe(2);
});

it('adopts an unregistered source already at its calculated managed destination', function (): void {
    $app = OrbitApp::query()->create([
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
            'app_id' => $app->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.app_instance.checkout_path', $path);

    expect($this->destinationGuard->paths)->toBe([]);
});

it('preflights every member source profile before reservation or relocation', function (): void {
    $app = OrbitApp::query()->create([
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
            'app_id' => $app->id,
        ])
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'app-dev.source_metadata_unsafe');

    expect($this->configuration->inspected)
        ->toBe($paths)
        ->and(AppInstance::query()->count())
        ->toBe(0)
        ->and($this->registrationSource->calls)
        ->toBe(['inspect']);
});

it('refuses retained source identity replacement before activation', function (): void {
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $payload = ['source_path' => '/work/acme', 'app_id' => $app->id];
    $this->projection->fail = true;
    $this->postJson('/api/v1/instances/register', $payload)->assertStatus(502);
    $this->registrationSource->retainedInvalid = true;

    $this
        ->postJson('/api/v1/instances/register', $payload)
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.registration_conflict');

    expect(AppInstance::query()->count())->toBe(1)->and(Route::query()->count())->toBe(1);
});

it('refuses a source nested in each existing managed checkout type before relocation', function (string $owner): void {
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $other = OrbitApp::query()->create([
        'name' => 'Other',
        'slug' => 'other',
        'repository_url' => 'https://github.com/acme/other.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $source = '/managed/other/nested/acme';

    if ($owner === 'app-instance') {
        AppInstance::query()->create([
            'app_id' => $other->id,
            'node_id' => $this->node->id,
            'name' => 'default',
            'environment' => 'development',
            'checkout_path' => '/managed/other',
            'status' => AppInstanceState::Active,
        ]);
    } else {
        $legacy = Instance::query()->create([
            'app_id' => $other->id,
            'node_id' => $this->node->id,
            'name' => 'default',
            'environment' => 'development',
            'checkout_path' => '/managed/other',
            'document_root' => '/managed/other/public',
            'php_version' => '8.4',
            'hostname' => 'other.test',
            'certificate_mode' => CertificateMode::OrbitCa,
            'status' => LifecycleStatus::Active,
        ]);

        if ($owner === 'workspace') {
            $legacy->update(['checkout_path' => '/managed/legacy']);
            Workspace::query()->create([
                'instance_id' => $legacy->id,
                'name' => 'other',
                'branch' => 'feature',
                'checkout_path' => '/managed/other',
                'php_version' => '8.4',
                'hostname' => 'workspace.test',
                'status' => LifecycleStatus::Active,
            ]);
        }
    }

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
            'app_id' => $app->id,
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.source_conflict');

    expect($this->registrationSource->calls)->toBe(['inspect']);
})->with(['app-instance', 'legacy-instance', 'workspace']);

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

/** @return array<string, mixed> */
function registration_evidence_for_test(string $source): array
{
    return [
        'registration_original_path' => $source,
        'registration_request_id' => (string) Illuminate\Support\Str::uuid(),
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
        'registration_route_hostname' => null,
        'registration_route_provenance' => RouteProvenance::Generated->value,
    ];
}

/** @return array{app_instance: array<string, mixed>, route: array{id: int, hostname: string, provenance: string}} */
function registration_migration_recovery(AppInstance $instance, Route $route): array
{
    return [
        'app_instance' => [
            'name' => 'main',
            'source_layout' => 'checkout',
            'checkout_path' => '/work/acme',
            'root' => null,
            'branch' => 'main',
            'branch_override' => null,
            'migration_required' => 1,
            'starting_commit' => $instance->starting_commit,
            'selected_php_version' => '8.4',
            'source_is_laravel' => 0,
            'provisioning_step' => 'active',
            'status' => AppInstanceState::Active->value,
        ],
        'route' => [
            'id' => $route->id,
            'hostname' => $route->hostname,
            'provenance' => $route->provenance->value,
        ],
    ];
}

/** @param array{string, string} $paths */
function registration_set_facts(array $paths): array
{
    return [
        new RegistrationSourceFacts(
            path: $paths[0],
            layout: AppInstanceSourceLayout::Checkout,
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
            layout: AppInstanceSourceLayout::Worktree,
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
