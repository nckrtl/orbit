<?php

declare(strict_types=1);

use App\Actions\AppInstances\CreateAppInstanceAction;
use App\Data\AppInstances\CreateAppInstanceData;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppInstances\AppInstanceDestinationGuard;
use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\DevelopmentAppInstanceConfigurator;
use App\Domain\AppInstances\DevelopmentAppInstanceProvisioner;
use App\Domain\AppInstances\DevelopmentAppInstanceSourceLifecycle;
use App\Domain\AppInstances\DevelopmentRouteProjector;
use App\Domain\AppInstances\DevelopmentSourceProfile;
use App\Domain\AppInstances\DevelopmentSourceResolution;
use App\Domain\AppInstances\Removal\AppInstanceRemovalProjector;
use App\Domain\AppInstances\Removal\AppInstanceSourceInventory;
use App\Domain\AppInstances\Removal\DevelopmentAppInstanceSourceRemoval;
use App\Domain\Clusters\ClusterState;
use App\Domain\Instances\CertificateMode;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Activity;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceRemovalMember;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Route;
use App\Models\RouteTarget;
use App\Models\Workspace;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->destination = new class implements AppInstanceDestinationGuard {
        public bool $occupied = false;

        public function assertUnoccupied(Node $node, \App\Domain\Nodes\Storage\StoragePath $destination): void
        {
            if ($this->occupied) {
                throw new ResourceOperationException(
                    'instance.migration_conflict',
                    'AppInstance destination is occupied by unmanaged data.',
                    409,
                );
            }
        }
    };
    app()->instance(AppInstanceDestinationGuard::class, $this->destination);
    app()->instance(RoleBaselineConverger::class, new class implements RoleBaselineConverger {
        public function converge(Node $node, NodeRole $assignment): void {}

        public function remove(Node $node, NodeRole $assignment, bool $purgeData): void {}

        public function removeUnreachable(Node $node, NodeRole $assignment): void {}
    });
    app()->instance(DevelopmentAppInstanceConfigurator::class, new class implements DevelopmentAppInstanceConfigurator {
        public function inspect(AppInstance $appInstance): DevelopmentSourceProfile
        {
            return new DevelopmentSourceProfile('8.5', false);
        }

        public function configureLaravelUrl(AppInstance $appInstance, string $url): void {}
    });
    app()->instance(DevelopmentRouteProjector::class, new class implements DevelopmentRouteProjector {
        public function converge(AppInstance $appInstance, Route $route): void {}
    });
    app()->instance(ManagedUserAccountResolver::class, new class implements ManagedUserAccountResolver {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
        }
    });
    $this->source = new class implements DevelopmentAppInstanceSourceLifecycle {
        /** @var list<string> */
        public array $calls = [];

        /** @var list<bool> */
        public array $prepareExisting = [];

        public ?string $fail = null;

        public string $failureCode = 'instance.source_interrupted';

        public DevelopmentSourceResolution $resolution;

        public function __construct()
        {
            $this->resolution = new DevelopmentSourceResolution('dev', str_repeat('a', 40));
        }

        public function prepare(AppInstance $appInstance, bool $allowExisting): void
        {
            $this->prepareExisting[] = $allowExisting;
            $this->record('prepare', $appInstance);
        }

        public function inspectPrepared(AppInstance $appInstance): void
        {
            $this->record('inspect-prepared', $appInstance);
        }

        public function resolve(AppInstance $appInstance): DevelopmentSourceResolution
        {
            $this->record('resolve', $appInstance);

            return $this->resolution;
        }

        public function inspectResolved(AppInstance $appInstance): DevelopmentSourceResolution
        {
            $this->record('inspect-resolved', $appInstance);

            return $this->resolution;
        }

        private function record(string $operation, AppInstance $appInstance): void
        {
            $this->calls[] = "{$operation}:{$appInstance->status->value}";

            if ($this->fail === $operation) {
                throw new ResourceOperationException($this->failureCode, 'Source operation interrupted.');
            }
        }
    };
    app()->instance(DevelopmentAppInstanceSourceLifecycle::class, $this->source);
    $this->removalSource = new class implements DevelopmentAppInstanceSourceRemoval {
        /** @var list<string> */
        public array $calls = [];

        public ?string $fail = null;

        /** @var array<int, list<string>> */
        public array $linkedPaths = [];

        public function inspect(AppInstance $appInstance, bool $force): AppInstanceSourceInventory
        {
            $this->record('inspect', $appInstance->id);
            $paths = $this->linkedPaths[$appInstance->id] ?? [$appInstance->checkout_path];
            $payload = "{$appInstance->id}\0{$appInstance->checkout_path}";

            return new AppInstanceSourceInventory(
                appInstanceId: $appInstance->id,
                layout: $appInstance->source_layout,
                repositoryIdentity: $appInstance->app->repository_identity,
                checkoutPath: $appInstance->checkout_path,
                root: dirname(dirname($appInstance->checkout_path)),
                branch: $appInstance->branch,
                startingCommit: $appInstance->starting_commit,
                commonRepositoryPath: $appInstance->source_layout === 'checkout'
                    ? $appInstance->checkout_path
                    : dirname(dirname($appInstance->checkout_path)),
                linkedWorktreePaths: $paths,
                digest: hash('sha256', $payload),
            );
        }

        public function prepare(AppInstanceRemovalMember $member): void
        {
            $this->record('prepare', $member->app_instance_id);
        }

        public function revalidate(AppInstanceRemovalMember $member): void
        {
            $this->record('revalidate', $member->app_instance_id);
        }

        public function finalize(AppInstanceRemovalMember $member): string
        {
            $this->record('finalize', $member->app_instance_id);

            return hash('sha256', "receipt\0{$member->source_digest}");
        }

        private function record(string $operation, int $id): void
        {
            $this->calls[] = "{$operation}:{$id}";

            if ($this->fail === $operation) {
                throw new ResourceOperationException(
                    'instance.source_interrupted',
                    'Source operation interrupted.',
                    502,
                );
            }
        }
    };
    app()->instance(DevelopmentAppInstanceSourceRemoval::class, $this->removalSource);
    $this->removalProjector = new class implements AppInstanceRemovalProjector {
        /** @var list<string> */
        public array $calls = [];

        public ?string $fail = null;

        public function clearRouteTarget(AppInstanceRemovalMember $member): string
        {
            $this->record('route', $member);
            $route = Route::query()->find($member->route_id);

            if (! $route instanceof Route) {
                return 'deleted';
            }

            $route->targets()->where('app_instance_id', $member->app_instance_id)->delete();

            if ($route->targets()->exists()) {
                return 'retained';
            }

            $route->delete();

            return 'deleted';
        }

        public function cleanupRuntime(AppInstanceRemovalMember $member): void
        {
            $this->record('runtime', $member);
        }

        private function record(string $operation, AppInstanceRemovalMember $member): void
        {
            $this->calls[] = "{$operation}:{$member->app_instance_id}";

            if ($this->fail === $operation) {
                throw new ResourceOperationException(
                    'instance.runtime_interrupted',
                    'Removal projection interrupted.',
                    502,
                );
            }
        }
    };
    app()->instance(AppInstanceRemovalProjector::class, $this->removalProjector);

    $this->node = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'tld' => 'test',
        'public_ssh_host' => '192.0.2.10',
        'wireguard_ip' => '10.44.0.3',
        'user' => 'orbit',
        'settings' => ['apps' => ['path' => '/srv/orbit/apps']],
    ]);
    $this->node
        ->roles()
        ->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);
    $operator = Node::query()->create([
        'name' => 'operator',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
    ]);
    $this->markAsGateway($operator);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    $this->orbitApp = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/site.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
});

it('creates an active checkout AppInstance on a standalone Node with inherited root', function (): void {
    $requestId = (string) Str::uuid();
    $response = $this->postJson(
        '/api/v1/instances',
        [
            'app_id' => $this->orbitApp->id,
            'node_id' => $this->node->id,
            'name' => 'dev',
        ],
        ['X-Orbit-Request-Id' => $requestId],
    );

    $response
        ->assertCreated()
        ->assertJsonMissingPath('data.cluster_id')
        ->assertJsonPath('data.source_layout', 'checkout')
        ->assertJsonPath('data.checkout_path', '/srv/orbit/apps/acme/dev')
        ->assertJsonPath('data.root', null)
        ->assertJsonPath('data.effective_root', 'public')
        ->assertJsonPath('data.selected_branch', 'dev')
        ->assertJsonPath('data.branch_override', null)
        ->assertJsonPath('data.migration_required', false)
        ->assertJsonMissingPath('data.branch')
        ->assertJsonPath('data.starting_commit', str_repeat('a', 40))
        ->assertJsonPath('data.status', 'active');

    expect(AppInstance::query()->count())
        ->toBe(1)
        ->and($this->source->calls)
        ->toBe([
            'prepare:reserved',
            'inspect-prepared:checkout_prepared',
            'resolve:checkout_prepared',
            'inspect-prepared:source_resolved',
            'inspect-resolved:source_resolved',
        ])
        ->and($this->source->prepareExisting)
        ->toBe([false])
        ->and(AppInstance::query()->sole()->source_layout)
        ->toBe(AppInstanceSourceLayout::Checkout->value)
        ->and(Activity::query()->where('request_id', $requestId)->sole()->subject_type)
        ->toBe(AppInstance::class)
        ->and(Activity::query()->where('request_id', $requestId)->sole()->properties?->get('source_layout'))
        ->toBe('checkout')
        ->and(Activity::query()->where('request_id', $requestId)->sole()->properties?->get('branch_override'))
        ->toBeNull()
        ->and(Activity::query()->where('request_id', $requestId)->sole()->properties?->get('migration_required'))
        ->toBeFalse()
        ->and(Schema::hasColumn('app_instances', 'source_layout'))
        ->toBeTrue()
        ->and(Schema::hasColumn('app_instances', 'cluster_id'))
        ->toBeFalse()
        ->and(Route::query()->sole()->getAttributes())
        ->toMatchArray([
            'app_id' => $this->orbitApp->id,
            'node_id' => $this->node->id,
            'cluster_id' => null,
            'generation_basis_node_id' => $this->node->id,
            'hostname' => 'dev.acme.test',
            'provenance' => 'generated',
            'publication' => 'private',
            'status' => 'active',
            'failed_step' => null,
            'error_code' => null,
        ])
        ->and(Route::query()->sole()->targets()->sole()->app_instance_id)
        ->toBe(AppInstance::query()->sole()->id);
});

it('keeps explicit branch selection separate from default identity and Route identity', function (): void {
    $this->source->resolution = new DevelopmentSourceResolution('release', str_repeat('b', 40));

    $response = $this->postJson('/api/v1/instances', [
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'default',
        'branch' => 'release',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.name', 'default')
        ->assertJsonPath('data.checkout_path', '/srv/orbit/apps/acme/default')
        ->assertJsonPath('data.selected_branch', 'release')
        ->assertJsonPath('data.branch_override', 'release')
        ->assertJsonPath('data.hostname', 'acme.test');
});

it('retains explicit override intent when it equals the App default branch', function (): void {
    $this->source->resolution = new DevelopmentSourceResolution('main', str_repeat('b', 40));

    $this
        ->postJson('/api/v1/instances', [
            'app_id' => $this->orbitApp->id,
            'node_id' => $this->node->id,
            'name' => 'default',
            'branch' => 'main',
        ])
        ->assertCreated()
        ->assertJsonPath('data.selected_branch', 'main')
        ->assertJsonPath('data.branch_override', 'main');
});

it('rejects invalid branch input before persistence or source work', function (): void {
    $this
        ->postJson('/api/v1/instances', [
            'app_id' => $this->orbitApp->id,
            'node_id' => $this->node->id,
            'name' => 'default',
            'branch' => '../release',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertJsonPath('error.details.branch.0', 'The branch is not a valid Git branch name.');

    expect(AppInstance::query()->count())
        ->toBe(0)
        ->and(Route::query()->count())
        ->toBe(0)
        ->and($this->source->calls)
        ->toBe([]);
});

it('reports an absent explicit remote branch without fallback or publication', function (): void {
    $this->source->fail = 'resolve';
    $this->source->failureCode = 'instance.branch_resolution_failed';

    $this
        ->postJson('/api/v1/instances', [
            'app_id' => $this->orbitApp->id,
            'node_id' => $this->node->id,
            'name' => 'default',
            'branch' => 'missing',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'instance.branch_resolution_failed');

    expect(AppInstance::query()->sole()->branch_override)
        ->toBe('missing')
        ->and(AppInstance::query()->sole()->status)
        ->toBe(AppInstanceState::CheckoutPrepared)
        ->and(Route::query()->sole()->status)
        ->toBe(RouteStatus::Failed);
});

it('rejects added removed or changed branch override on creation retry before mutation', function (
    ?string $original,
    ?string $retry,
): void {
    $this->source->resolution = new DevelopmentSourceResolution($original ?? 'dev', str_repeat('b', 40));
    $payload = [
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ];

    if ($original !== null) {
        $payload['branch'] = $original;
    }

    $this->postJson('/api/v1/instances', $payload)->assertCreated();
    $before = AppInstance::query()->sole()->getAttributes();
    $this->source->calls = [];

    if ($retry === null) {
        unset($payload['branch']);
    } else {
        $payload['branch'] = $retry;
    }

    $this
        ->postJson('/api/v1/instances', $payload)
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.placement_conflict');

    expect(AppInstance::query()->sole()->getAttributes())
        ->toBe($before)
        ->and($this->source->calls)
        ->toBe([]);
})->with([
    'changed' => ['release', 'hotfix'],
    'removed' => ['release', null],
    'added' => [null, 'release'],
]);

it('creates explicit Routes during provisioning and preserves exact retry identity', function (): void {
    $this->source->resolution = new DevelopmentSourceResolution('main', str_repeat('a', 40));
    $payload = [
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'default',
        'hostname' => 'Preview.Example.Test',
    ];

    $created = $this->postJson('/api/v1/instances', $payload)->assertCreated();
    $route = Route::query()->sole();

    expect($route->getAttributes())
        ->toMatchArray([
            'hostname' => 'preview.example.test',
            'provenance' => 'explicit',
            'generation_basis_node_id' => null,
            'status' => 'active',
            'failed_step' => null,
            'error_code' => null,
        ]);

    $this
        ->postJson('/api/v1/instances', $payload)
        ->assertOk()
        ->assertJsonPath('data.id', $created->json('data.id'));

    expect(Route::query()->count())->toBe(1);
});

it('refuses unavailable generated naming before source mutation and completes on retry', function (): void {
    $this->node->update(['tld' => null]);
    $payload = [
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ];

    $this
        ->postJson('/api/v1/instances', $payload)
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.tld_required');

    $instance = AppInstance::query()->sole();
    expect($instance->status)
        ->toBe(AppInstanceState::Reserved)
        ->and($instance->starting_commit)
        ->toBeNull()
        ->and($this->source->calls)
        ->toBe([])
        ->and(Route::query()->count())
        ->toBe(0);

    $this->node->update(['tld' => 'test']);
    $this->postJson('/api/v1/instances', $payload)->assertOk();

    expect(Route::query()->sole()->hostname)->toBe('dev.acme.test');
});

it('uses Node TLD before active Cluster fallback while Cluster membership selects scope', function (): void {
    $this->source->resolution = new DevelopmentSourceResolution('main', str_repeat('a', 40));
    $cluster = Cluster::query()->create([
        'name' => 'routing',
        'state' => ClusterState::Active,
        'tld' => 'cluster.test',
    ]);
    $this->node->update(['cluster_id' => $cluster->id]);
    $this->node
        ->roles()
        ->create([
            'cluster_id' => $cluster->id,
            'role' => RoleName::Router,
            'status' => LifecycleStatus::Active,
        ]);

    $this->postJson('/api/v1/instances', [
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'default',
    ])->assertCreated();

    expect(Route::query()->sole()->hostname)
        ->toBe('acme.test')
        ->and(Route::query()->sole()->cluster_id)
        ->toBe($cluster->id);

    AppInstance::query()->sole()->update(['status' => AppInstanceState::SourceResolved]);
    Route::query()->sole()->update(['status' => 'pending']);
    Route::query()->sole()->delete();
    AppInstance::query()->sole()->delete();
    $this->node->update(['tld' => null]);
    $this->source->resolution = new DevelopmentSourceResolution('feature', str_repeat('b', 40));

    $this->postJson('/api/v1/instances', [
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'feature',
    ])->assertCreated();

    expect(Route::query()->sole()->hostname)
        ->toBe('feature.acme.cluster.test')
        ->and(Route::query()->sole()->cluster_id)
        ->toBe($cluster->id);
});

it('creates equivalent source on Nodes in every optional Cluster state', function (string $placement): void {
    if ($placement !== 'standalone') {
        $cluster = Cluster::query()->create([
            'name' => $placement,
            'state' => $placement === 'inactive' ? ClusterState::Inactive : ClusterState::Active,
            'tld' => $placement === 'active-with-tld' ? 'orbit' : null,
        ]);
        $this->node->update(['cluster_id' => $cluster->id]);

        if ($cluster->state === ClusterState::Active) {
            $this->node
                ->roles()
                ->create([
                    'cluster_id' => $cluster->id,
                    'role' => RoleName::Router,
                    'status' => LifecycleStatus::Active,
                ]);
        }
    }

    $this
        ->postJson('/api/v1/instances', [
            'app_id' => $this->orbitApp->id,
            'node_id' => $this->node->id,
            'name' => 'dev',
        ])
        ->assertCreated()
        ->assertJsonMissingPath('data.cluster_id')
        ->assertJsonPath('data.source_layout', 'checkout')
        ->assertJsonPath('data.status', 'active');

    expect(AppInstance::query()->sole()->getAttributes())->not->toHaveKey('cluster_id');
})->with(['standalone', 'inactive', 'active-without-tld', 'active-with-tld']);

it('refuses Cluster activation that would change an active AppInstance Route', function (): void {
    $created = $this->postJson('/api/v1/instances', [
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ])->assertCreated();
    $before = AppInstance::query()->findOrFail($created->json('data.id'))->getAttributes();
    $this->source->calls = [];
    $cluster = Cluster::query()->create(['name' => 'routing', 'state' => ClusterState::Inactive]);
    $firstRouter = Node::query()->create([
        'cluster_id' => $cluster->id,
        'name' => 'router-one',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.20',
        'wireguard_ip' => '10.44.0.20',
    ]);
    $secondRouter = Node::query()->create([
        'cluster_id' => $cluster->id,
        'name' => 'router-two',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.21',
        'wireguard_ip' => '10.44.0.21',
    ]);

    $this->putJson("/api/v1/clusters/{$cluster->id}/nodes/{$this->node->id}")->assertOk();
    $this->putJson("/api/v1/clusters/{$cluster->id}/router/{$firstRouter->id}")->assertOk();
    $this->patchJson("/api/v1/clusters/{$cluster->id}", ['tld' => 'orbit'])->assertOk();
    $this
        ->patchJson("/api/v1/clusters/{$cluster->id}", ['state' => 'active'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.reconciliation_required');

    expect(AppInstance::query()->findOrFail($created->json('data.id'))->getAttributes())
        ->toBe($before)
        ->and($cluster->refresh()->state)
        ->toBe(ClusterState::Inactive)
        ->and(Route::query()->sole()->only(['status', 'node_id', 'cluster_id', 'hostname']))
        ->toBe([
            'status' => RouteStatus::Active,
            'node_id' => $this->node->id,
            'cluster_id' => null,
            'hostname' => 'dev.acme.test',
        ])
        ->and($firstRouter->roles()->where('role', RoleName::Router)->sole()->status)
        ->toBe(LifecycleStatus::Active)
        ->and($secondRouter->roles()->where('role', RoleName::Router)->exists())
        ->toBeFalse()
        ->and($this->source->calls)
        ->toBeEmpty();
});

it('transports a root override and returns it as the effective root', function (): void {
    $this
        ->postJson('/api/v1/instances', [
            'app_id' => $this->orbitApp->id,
            'node_id' => $this->node->id,
            'name' => 'dev',
            'root' => 'site/public',
        ])
        ->assertCreated()
        ->assertJsonPath('data.root', 'site/public')
        ->assertJsonPath('data.effective_root', 'site/public');
});

it('fails before mutation when a legacy App has incomplete source defaults', function (): void {
    $this->orbitApp->update(['default_branch' => null, 'root' => null]);

    $this
        ->postJson('/api/v1/instances', [
            'app_id' => $this->orbitApp->id,
            'node_id' => $this->node->id,
            'name' => 'dev',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'app.source_defaults_incomplete');

    expect(AppInstance::query()->count())
        ->toBe(0)
        ->and($this->source->calls)
        ->toBeEmpty();
});

it('persists each durable state and resumes the next transition', function (
    string $failure,
    AppInstanceState $durableState,
): void {
    $payload = [
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ];
    $this->source->fail = $failure;

    $this->postJson('/api/v1/instances', $payload)->assertUnprocessable();
    expect(AppInstance::query()->sole()->status)->toBe($durableState);
    $this->source->fail = null;

    $this
        ->postJson('/api/v1/instances', $payload)
        ->assertOk()
        ->assertJsonPath('data.status', 'active');
    expect(AppInstance::query()->count())->toBe(1);

    if ($failure === 'prepare') {
        expect($this->source->prepareExisting)->toBe([false, true]);
    }
})->with([
    'reserved' => ['prepare', AppInstanceState::Reserved],
    'checkout prepared' => ['resolve', AppInstanceState::CheckoutPrepared],
    'source resolved' => ['inspect-resolved', AppInstanceState::SourceResolved],
]);

it('keeps a failed attempt from overwriting a successful retry after lease release', function (): void {
    $data = new CreateAppInstanceData(
        appId: $this->orbitApp->id,
        nodeId: $this->node->id,
        name: 'dev',
        root: null,
        hostname: null,
        branch: null,
    );
    $native = app(DevelopmentAppInstanceProvisioner::class);
    $provisioner = new class($native) implements DevelopmentAppInstanceProvisioner {
        public int $completions = 0;

        public function __construct(
            private readonly DevelopmentAppInstanceProvisioner $native,
        ) {}

        public function reserve(AppInstance $appInstance, ?string $hostname): void
        {
            $this->native->reserve($appInstance, $hostname);
        }

        public function complete(AppInstance $appInstance, ?string $hostname): AppInstance
        {
            $this->completions++;

            if ($this->completions === 1) {
                throw new ResourceOperationException('instance.first_attempt_failed', 'The first attempt failed.');
            }

            return $this->native->complete($appInstance, $hostname);
        }
    };
    app()->instance(DevelopmentAppInstanceProvisioner::class, $provisioner);
    $lock = new class($data) implements AppDevSourceOperationLock {
        public int $leases = 0;

        public function __construct(
            private readonly CreateAppInstanceData $data,
        ) {}

        public function synchronized(int $nodeId, \Closure $operation): mixed
        {
            $this->leases++;

            try {
                return $operation();
            } catch (\Throwable $exception) {
                if ($this->leases === 1) {
                    app(CreateAppInstanceAction::class)->execute($this->data);
                }

                throw $exception;
            }
        }
    };
    app()->instance(AppDevSourceOperationLock::class, $lock);

    expect(fn () => app(CreateAppInstanceAction::class)->execute($data))
        ->toThrow(ResourceOperationException::class, 'The first attempt failed.');

    expect($lock->leases)
        ->toBe(2)
        ->and($provisioner->completions)
        ->toBe(2)
        ->and(AppInstance::query()->sole()->only(['status', 'failed_step', 'error_code']))
        ->toBe([
            'status' => AppInstanceState::Active,
            'failed_step' => null,
            'error_code' => null,
        ])
        ->and(Route::query()->sole()->only(['status', 'failed_step', 'error_code']))
        ->toBe([
            'status' => RouteStatus::Active,
            'failed_step' => null,
            'error_code' => null,
        ]);
});

it('persists unexpected provisioning failures before releasing the lease', function (): void {
    $native = app(DevelopmentAppInstanceProvisioner::class);
    app()->instance(DevelopmentAppInstanceProvisioner::class, new class($native) implements
        DevelopmentAppInstanceProvisioner {
        public function __construct(
            private readonly DevelopmentAppInstanceProvisioner $native,
        ) {}

        public function reserve(AppInstance $appInstance, ?string $hostname): void
        {
            $this->native->reserve($appInstance, $hostname);
        }

        public function complete(AppInstance $appInstance, ?string $hostname): AppInstance
        {
            throw new \LogicException('Unexpected provisioning failure.');
        }
    });

    expect(fn () => app(CreateAppInstanceAction::class)->execute(new CreateAppInstanceData(
        appId: $this->orbitApp->id,
        nodeId: $this->node->id,
        name: 'dev',
        root: null,
        hostname: null,
        branch: null,
    )))
        ->toThrow(\LogicException::class, 'Unexpected provisioning failure.');

    expect(AppInstance::query()->sole()->only(['status', 'failed_step', 'error_code']))
        ->toBe([
            'status' => AppInstanceState::SourceResolved,
            'failed_step' => 'provisioning',
            'error_code' => 'instance.provisioning_failed',
        ])
        ->and(Route::query()->sole()->only(['status', 'failed_step', 'error_code']))
        ->toBe([
            'status' => RouteStatus::Failed,
            'failed_step' => 'provisioning',
            'error_code' => 'instance.provisioning_failed',
        ]);
});

it('does not reserve or persist failure evidence when lease acquisition fails', function (): void {
    $provisioner = new class implements DevelopmentAppInstanceProvisioner {
        public int $reservations = 0;

        public function reserve(AppInstance $appInstance, ?string $hostname): void
        {
            $this->reservations++;
        }

        public function complete(AppInstance $appInstance, ?string $hostname): AppInstance
        {
            return $appInstance;
        }
    };
    app()->instance(DevelopmentAppInstanceProvisioner::class, $provisioner);
    app()->instance(AppDevSourceOperationLock::class, new class implements AppDevSourceOperationLock {
        public function synchronized(int $nodeId, \Closure $operation): mixed
        {
            throw new \RuntimeException('Lease acquisition failed.');
        }
    });

    expect(fn () => app(CreateAppInstanceAction::class)->execute(new CreateAppInstanceData(
        appId: $this->orbitApp->id,
        nodeId: $this->node->id,
        name: 'dev',
        root: null,
        hostname: null,
        branch: null,
    )))
        ->toThrow(\RuntimeException::class, 'Lease acquisition failed.');

    expect($provisioner->reservations)
        ->toBe(0)
        ->and(Route::query()->count())
        ->toBe(0)
        ->and(AppInstance::query()->sole()->only(['status', 'failed_step', 'error_code']))
        ->toBe([
            'status' => AppInstanceState::Reserved,
            'failed_step' => null,
            'error_code' => null,
        ]);
});

it('persists reservation conflicts before releasing the lease', function (): void {
    $lock = new class implements AppDevSourceOperationLock {
        public bool $held = false;

        public bool $failurePersistedWhileHeld = false;

        public function synchronized(int $nodeId, \Closure $operation): mixed
        {
            $this->held = true;

            try {
                return $operation();
            } catch (\Throwable $exception) {
                $instance = AppInstance::query()->sole();
                $this->failurePersistedWhileHeld =
                    $instance->failed_step === 'source-prepare' && $instance->error_code === 'route.hostname_taken';

                throw $exception;
            } finally {
                $this->held = false;
            }
        }
    };
    $provisioner = new class($lock) implements DevelopmentAppInstanceProvisioner {
        public bool $reservedWhileHeld = false;

        public function __construct(
            private readonly AppDevSourceOperationLock $lock,
        ) {}

        public function reserve(AppInstance $appInstance, ?string $hostname): void
        {
            $this->reservedWhileHeld = $this->lock->held;

            throw new ResourceOperationException('route.hostname_taken', 'The hostname is unavailable.', 409);
        }

        public function complete(AppInstance $appInstance, ?string $hostname): AppInstance
        {
            return $appInstance;
        }
    };
    app()->instance(AppDevSourceOperationLock::class, $lock);
    app()->instance(DevelopmentAppInstanceProvisioner::class, $provisioner);

    expect(fn () => app(CreateAppInstanceAction::class)->execute(new CreateAppInstanceData(
        appId: $this->orbitApp->id,
        nodeId: $this->node->id,
        name: 'dev',
        root: null,
        hostname: 'dev.example.test',
        branch: null,
    )))
        ->toThrow(ResourceOperationException::class, 'The hostname is unavailable.');

    expect($provisioner->reservedWhileHeld)
        ->toBeTrue()
        ->and($lock->failurePersistedWhileHeld)
        ->toBeTrue()
        ->and($lock->held)
        ->toBeFalse()
        ->and(AppInstance::query()->sole()->only(['status', 'failed_step', 'error_code']))
        ->toBe([
            'status' => AppInstanceState::Reserved,
            'failed_step' => 'source-prepare',
            'error_code' => 'route.hostname_taken',
        ]);
});

it('rejects a retry on another Node before source work or state mutation', function (): void {
    $this->postJson('/api/v1/instances', [
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ])->assertCreated();
    $otherNode = Node::query()->create([
        'name' => 'other-app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20',
        'wireguard_ip' => '10.44.0.4',
        'user' => 'orbit',
    ]);
    $otherNode->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $before = AppInstance::query()->sole()->getAttributes();
    $this->source->calls = [];

    $this
        ->postJson('/api/v1/instances', [
            'app_id' => $this->orbitApp->id,
            'node_id' => $otherNode->id,
            'name' => 'dev',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.placement_conflict');

    expect(AppInstance::query()->sole()->getAttributes())
        ->toBe($before)
        ->and($this->source->calls)
        ->toBeEmpty();
});

it('rejects inactive Node role and unsupported platform placement before mutation', function (string $invalid): void {
    if ($invalid === 'node') {
        $this->node->update(['status' => LifecycleStatus::Failed]);
    } elseif ($invalid === 'role') {
        $this->node->roles()->delete();
    } else {
        $this->node->update(['platform' => 'darwin']);
    }

    $this
        ->postJson('/api/v1/instances', [
            'app_id' => $this->orbitApp->id,
            'node_id' => $this->node->id,
            'name' => 'dev',
        ])
        ->assertUnprocessable();

    expect(AppInstance::query()->count())
        ->toBe(0)
        ->and($this->source->calls)
        ->toBeEmpty();
})->with(['node', 'role', 'platform']);

it('rejects overlap with every retained legacy checkout type before source work', function (string $owner): void {
    $legacy = Instance::query()->create([
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'legacy',
        'environment' => 'development',
        'checkout_path' => $owner === 'instance'
            ? '/srv/orbit/apps/acme'
            : '/srv/orbit/legacy/acme',
        'hostname' => 'legacy.example.test',
        'certificate_mode' => CertificateMode::OrbitCa,
        'status' => LifecycleStatus::Active,
    ]);

    if ($owner === 'workspace') {
        Workspace::query()->create([
            'instance_id' => $legacy->id,
            'name' => 'dev',
            'branch' => 'dev',
            'checkout_path' => '/srv/orbit/apps/acme/dev',
            'hostname' => 'dev.example.test',
            'status' => LifecycleStatus::Active,
        ]);
    }

    $this
        ->postJson('/api/v1/instances', [
            'app_id' => $this->orbitApp->id,
            'node_id' => $this->node->id,
            'name' => 'dev',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.path_taken');

    expect(AppInstance::query()->count())
        ->toBe(0)
        ->and($this->source->calls)
        ->toBeEmpty();
})->with(['instance', 'workspace']);

it('keeps the first checkout immutable when a later AppInstance uses a changed apps root', function (): void {
    $first = $this->postJson('/api/v1/instances', [
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ])->assertCreated();
    $this->node->update(['settings' => ['apps' => ['path' => '/mnt/orbit/apps']]]);
    $this->source->resolution = new DevelopmentSourceResolution('feature', str_repeat('b', 40));

    $second = $this->postJson('/api/v1/instances', [
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'feature',
    ])->assertCreated();

    expect($first->json('data.checkout_path'))
        ->toBe('/srv/orbit/apps/acme/dev')
        ->and($second->json('data.checkout_path'))
        ->toBe('/mnt/orbit/apps/acme/feature')
        ->and(AppInstance::query()->findOrFail($first->json('data.id'))->checkout_path)
        ->toBe('/srv/orbit/apps/acme/dev');
});

it('rejects immutable root and source-layout conflicts on retry', function (string $conflict): void {
    $payload = [
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ];
    $this->postJson('/api/v1/instances', $payload)->assertCreated();
    $this->source->calls = [];

    if ($conflict === 'root') {
        $payload['root'] = 'other/public';
    } else {
        AppInstance::query()->sole()->update(['source_layout' => 'worktree']);
    }
    $before = AppInstance::query()->sole()->getAttributes();

    $this
        ->postJson('/api/v1/instances', $payload)
        ->assertConflict();

    expect(AppInstance::query()->sole()->getAttributes())->toBe($before);

    expect($this->source->calls)->toBeEmpty();
})->with(['root', 'source layout']);

it('returns migration required before retry or removal mutates a legacy default', function (
    string $operation,
): void {
    $payload = [
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ];
    $created = $this->postJson('/api/v1/instances', $payload)->assertCreated();
    $instance = AppInstance::query()->sole();
    $instance->update(['migration_required' => true]);
    $before = $instance->refresh()->getAttributes();
    $this->source->calls = [];

    $response = $operation === 'retry'
        ? $this->postJson('/api/v1/instances', $payload)
        : $this->deleteJson("/api/v1/instances/{$created->json('data.id')}");

    $response
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.migration_required');
    expect(AppInstance::query()->sole()->getAttributes())
        ->toBe($before)
        ->and($this->source->calls)
        ->toBe([]);
})->with(['retry', 'remove']);

it('returns migration conflict for an occupied reserved default identity before mutation', function (): void {
    $this->source->resolution = new DevelopmentSourceResolution('main', str_repeat('b', 40));
    $payload = [
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'default',
    ];
    $this->postJson('/api/v1/instances', $payload)->assertCreated();
    AppInstance::query()->sole()->update(['checkout_path' => '/srv/orbit/apps/acme/legacy-default']);
    $before = AppInstance::query()->sole()->getAttributes();
    $this->source->calls = [];

    $this
        ->postJson('/api/v1/instances', $payload)
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.migration_conflict')
        ->assertJsonPath('error.message', 'The reserved default AppInstance identity is occupied by another source.');

    expect(AppInstance::query()->sole()->getAttributes())
        ->toBe($before)
        ->and($this->source->calls)
        ->toBe([]);
});

it('returns migration conflict for a managed default destination overlap before mutation', function (): void {
    Instance::query()->create([
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'legacy',
        'environment' => 'development',
        'checkout_path' => '/srv/orbit/apps/acme/default',
        'hostname' => 'legacy.example.test',
        'certificate_mode' => CertificateMode::OrbitCa,
        'status' => LifecycleStatus::Active,
    ]);

    $this
        ->postJson('/api/v1/instances', [
            'app_id' => $this->orbitApp->id,
            'node_id' => $this->node->id,
            'name' => 'default',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.migration_conflict');

    expect(AppInstance::query()->count())
        ->toBe(0)
        ->and($this->source->calls)
        ->toBe([]);
});

it('returns migration conflict for an unmanaged occupied default destination before mutation', function (): void {
    $this->destination->occupied = true;

    $this
        ->postJson('/api/v1/instances', [
            'app_id' => $this->orbitApp->id,
            'node_id' => $this->node->id,
            'name' => 'default',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.migration_conflict')
        ->assertJsonPath('error.message', 'AppInstance destination is occupied by unmanaged data.');

    expect(AppInstance::query()->count())
        ->toBe(0)
        ->and($this->source->calls)
        ->toBe([]);
});

it('treats active creation evidence as terminal when development HEAD advances', function (): void {
    $payload = [
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ];
    $created = $this->postJson('/api/v1/instances', $payload)->assertCreated();
    $before = AppInstance::query()->sole()->getAttributes();
    $this->source->calls = [];
    $this->source->resolution = new DevelopmentSourceResolution('dev', str_repeat('b', 40));

    $this
        ->postJson('/api/v1/instances', $payload)
        ->assertOk()
        ->assertJsonPath('data.id', $created->json('data.id'))
        ->assertJsonPath('data.starting_commit', str_repeat('a', 40));

    expect(AppInstance::query()->sole()->getAttributes())
        ->toBe($before)
        ->and($this->source->calls)
        ->toBe(['inspect-prepared:active']);
});

it('rejects repository execution and unsupported transport keys', function (): void {
    $this
        ->postJson('/api/v1/instances', [
            'app_id' => $this->orbitApp->id,
            'node_id' => $this->node->id,
            'name' => 'dev',
            'repository_url' => 'https://github.com/acme/other.git',
            'cluster_id' => 1,
            'source_layout' => 'checkout',
            'checkout_path' => '/tmp/acme',
            'command' => 'id',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');

    expect(AppInstance::query()->count())
        ->toBe(0)
        ->and($this->source->calls)
        ->toBeEmpty();
});

it('keeps overlapping AppInstance and legacy Instance IDs in separate endpoint domains', function (): void {
    $appInstance = $this->postJson('/api/v1/instances', [
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ])->assertCreated();
    $legacy = Instance::query()->create([
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'legacy',
        'environment' => 'development',
        'checkout_path' => '/srv/orbit/legacy/acme',
        'hostname' => 'legacy.example.test',
        'certificate_mode' => CertificateMode::OrbitCa,
        'status' => LifecycleStatus::Active,
    ]);
    $workspace = Workspace::query()->create([
        'instance_id' => $legacy->id,
        'name' => 'workspace',
        'branch' => 'workspace',
        'checkout_path' => '/srv/orbit/workspaces/acme/workspace',
        'hostname' => 'workspace.example.test',
        'status' => LifecycleStatus::Active,
    ]);

    expect($appInstance->json('data.id'))->toBe($legacy->id);
    $this
        ->getJson("/api/v1/instances/{$legacy->id}")
        ->assertOk()
        ->assertJsonPath('data.name', 'dev')
        ->assertJsonPath('data.source_layout', 'checkout');
    $this
        ->getJson("/api/v1/workspaces/{$workspace->id}")
        ->assertOk()
        ->assertJsonPath('data.instance_id', $legacy->id)
        ->assertJsonPath('data.name', 'workspace');
});

it('removes an active AppInstance through every durable checkpoint', function (bool $force): void {
    $created = $this->postJson('/api/v1/instances', [
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ])->assertCreated();
    $this->source->calls = [];

    $this
        ->deleteJson("/api/v1/instances/{$created->json('data.id')}", [
            'force' => $force,
        ])
        ->assertOk()
        ->assertJsonPath('data.id', $created->json('data.id'))
        ->assertJsonPath('data.name', 'dev')
        ->assertJsonPath('data.force', $force)
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.current_step', null)
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.completed', 1)
        ->assertJsonPath('data.remaining', 0)
        ->assertJsonPath('data.failed_step', null)
        ->assertJsonPath('data.error_code', null);

    $activity = Activity::query()->where('command', 'instance:remove')->sole();
    expect(AppInstance::query()->count())
        ->toBe(0)
        ->and(RouteTarget::query()->count())
        ->toBe(0)
        ->and(Route::query()->count())
        ->toBe(0)
        ->and($this->removalSource->calls)
        ->toBe([
            "inspect:{$created->json('data.id')}",
            "prepare:{$created->json('data.id')}",
            "revalidate:{$created->json('data.id')}",
            "inspect:{$created->json('data.id')}",
            "finalize:{$created->json('data.id')}",
        ])
        ->and($this->source->calls)
        ->toBeEmpty()
        ->and($activity->subject_type)
        ->toBe(AppInstance::class)
        ->and($activity->subject_id)
        ->toBe($created->json('data.id'))
        ->and($activity->target_node_id)
        ->toBe($this->node->id)
        ->and($activity->properties?->get('removal'))
        ->toMatchArray([
            'id' => $created->json('data.id'),
            'name' => 'dev',
            'force' => $force,
            'status' => 'completed',
            'current_step' => null,
            'total' => 1,
            'completed' => 1,
            'remaining' => 0,
            'failed_step' => null,
            'error_code' => null,
        ])
        ->not->toHaveKeys(['checkout_path', 'source_digest', 'finalization_receipt']);
})->with([false, true]);

it('keeps preflight refusals free of Route source and lifecycle mutation', function (): void {
    $created = $this->postJson('/api/v1/instances', [
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ])->assertCreated();
    $route = Route::query()->sole();
    $routeBefore = $route->toArray();
    $this->removalSource->fail = 'inspect';

    $response = $this->deleteJson("/api/v1/instances/{$created->json('data.id')}");

    $response
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'instance.source_interrupted')
        ->assertJsonPath('error.details', []);
    expect(AppInstance::query()->count())
        ->toBe(1)
        ->and(AppInstance::query()->sole()->status)
        ->toBe(AppInstanceState::Active)
        ->and(RouteTarget::query()->count())
        ->toBe(1)
        ->and($route->refresh()->toArray())
        ->toBe($routeBefore);
});

it('retains bounded failed progress and resumes without recreating a deleted Route', function (): void {
    $created = $this->postJson('/api/v1/instances', [
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ])->assertCreated();
    $id = $created->json('data.id');
    $this->removalProjector->fail = 'runtime';

    $this
        ->deleteJson("/api/v1/instances/{$id}")
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'instance.runtime_interrupted')
        ->assertJsonPath('error.details.removal.id', $id)
        ->assertJsonPath('error.details.removal.force', false)
        ->assertJsonPath('error.details.removal.status', 'failed')
        ->assertJsonPath('error.details.removal.current_step', 'runtime_cleanup')
        ->assertJsonPath('error.details.removal.total', 1)
        ->assertJsonPath('error.details.removal.completed', 0)
        ->assertJsonPath('error.details.removal.remaining', 1)
        ->assertJsonPath('error.details.removal.failed_step', 'runtime_cleanup')
        ->assertJsonPath('error.details.removal.error_code', 'instance.runtime_interrupted');

    expect(AppInstance::query()->count())
        ->toBe(1)
        ->and(AppInstance::query()->sole()->status)
        ->toBe(AppInstanceState::Removing)
        ->and(Route::query()->count())
        ->toBe(0);

    $this
        ->getJson("/api/v1/instances/{$id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'removing')
        ->assertJsonPath('data.removal.failed_step', 'runtime_cleanup');

    $failedActivity = Activity::query()->where('command', 'instance:remove')->latest('id')->firstOrFail();
    expect($failedActivity->status)
        ->toBe('failed')
        ->and($failedActivity->error_code)
        ->toBe('instance.runtime_interrupted')
        ->and($failedActivity->subject_type)
        ->toBe(AppInstance::class)
        ->and($failedActivity->subject_id)
        ->toBe($id)
        ->and($failedActivity->target_node_id)
        ->toBe($this->node->id)
        ->and($failedActivity->properties?->get('removal'))
        ->toMatchArray([
            'id' => $id,
            'force' => false,
            'status' => 'failed',
            'current_step' => 'runtime_cleanup',
            'total' => 1,
            'completed' => 0,
            'remaining' => 1,
            'failed_step' => 'runtime_cleanup',
            'error_code' => 'instance.runtime_interrupted',
        ]);

    $this
        ->postJson('/api/v1/instances', [
            'app_id' => $this->orbitApp->id,
            'node_id' => $this->node->id,
            'name' => 'dev',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.removal_conflict');

    $this->removalProjector->fail = null;
    $this
        ->deleteJson("/api/v1/instances/{$id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.completed', 1);

    expect(Route::query()->count())->toBe(0);
});

it('rejects a non-empty JSON array from the removal transport', function (): void {
    $created = $this->postJson('/api/v1/instances', [
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ])->assertCreated();
    $this->source->calls = [];

    $this
        ->call(
            'DELETE',
            "/api/v1/instances/{$created->json('data.id')}",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            content: '[false]',
        )
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');

    expect(AppInstance::query()->count())
        ->toBe(1)
        ->and($this->source->calls)
        ->toBeEmpty();
});

it('rejects the removed compatibility key', function (): void {
    $created = $this->postJson('/api/v1/instances', [
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ])->assertCreated();

    $this
        ->deleteJson("/api/v1/instances/{$created->json('data.id')}", [
            'discard'.'_source' => true,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');

    expect(AppInstance::query()->sole()->status)->toBe(AppInstanceState::Active);
});
