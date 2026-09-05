<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceSourceKind;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\DevelopmentAppInstanceConfigurator;
use App\Domain\AppInstances\DevelopmentAppInstanceSourceLifecycle;
use App\Domain\AppInstances\DevelopmentRouteProjector;
use App\Domain\AppInstances\DevelopmentSourceProfile;
use App\Domain\AppInstances\DevelopmentSourceResolution;
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

        public function remove(AppInstance $appInstance, bool $discardSource): void
        {
            $this->record($discardSource ? 'remove-discard' : 'remove', $appInstance);
        }

        private function record(string $operation, AppInstance $appInstance): void
        {
            $this->calls[] = "{$operation}:{$appInstance->status->value}";

            if ($this->fail === $operation) {
                throw new ResourceOperationException('instance.source_interrupted', 'Source operation interrupted.');
            }
        }
    };
    app()->instance(DevelopmentAppInstanceSourceLifecycle::class, $this->source);

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
        'main_branch' => 'main',
        'root' => 'public',
    ]);
});

it('creates an active managed-clone AppInstance on a standalone Node with inherited root', function (): void {
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
        ->assertJsonPath('data.source_kind', 'managed_clone')
        ->assertJsonPath('data.checkout_path', '/srv/orbit/apps/acme/dev')
        ->assertJsonPath('data.root', null)
        ->assertJsonPath('data.effective_root', 'public')
        ->assertJsonPath('data.selected_branch', 'dev')
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
        ->and(AppInstance::query()->sole()->source_kind)
        ->toBe(AppInstanceSourceKind::ManagedClone->value)
        ->and(Activity::query()->where('request_id', $requestId)->sole()->subject_type)
        ->toBe(AppInstance::class)
        ->and(Activity::query()->where('request_id', $requestId)->sole()->properties?->get('source_kind'))
        ->toBe('managed_clone')
        ->and(Schema::hasColumn('app_instances', 'source_kind'))
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

it('creates explicit Routes during provisioning and preserves exact retry identity', function (): void {
    $this->source->resolution = new DevelopmentSourceResolution('main', str_repeat('a', 40));
    $payload = [
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'main',
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
        'name' => 'main',
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
        ->assertJsonPath('data.source_kind', 'managed_clone')
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
    $this->orbitApp->update(['main_branch' => null, 'root' => null]);

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

it('rejects immutable root and source-kind conflicts on retry', function (string $conflict): void {
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
        AppInstance::query()->sole()->update(['source_kind' => 'registered_worktree']);
    }
    $before = AppInstance::query()->sole()->getAttributes();

    $this
        ->postJson('/api/v1/instances', $payload)
        ->assertConflict();

    expect(AppInstance::query()->sole()->getAttributes())->toBe($before);

    expect($this->source->calls)->toBeEmpty();
})->with(['root', 'source kind']);

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
            'source_kind' => 'managed_clone',
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
        ->assertJsonPath('data.source_kind', 'managed_clone');
    $this
        ->getJson("/api/v1/workspaces/{$workspace->id}")
        ->assertOk()
        ->assertJsonPath('data.instance_id', $legacy->id)
        ->assertJsonPath('data.name', 'workspace');
});

it('refuses active AppInstance removal before source mutation', function (bool $discard): void {
    $created = $this->postJson('/api/v1/instances', [
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ])->assertCreated();
    $route = Route::query()->sole();
    $routeBefore = $route->only([
        'app_id',
        'node_id',
        'cluster_id',
        'generation_basis_node_id',
        'hostname',
        'provenance',
        'publication',
        'status',
        'failed_step',
        'error_code',
    ]);
    $this->source->calls = [];

    $this
        ->deleteJson("/api/v1/instances/{$created->json('data.id')}", [
            'discard_source' => $discard,
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.reconciliation_required');

    expect(AppInstance::query()->count())
        ->toBe(1)
        ->and(RouteTarget::query()->count())
        ->toBe(1)
        ->and($route->refresh()->only(array_keys($routeBefore)))
        ->toBe($routeBefore)
        ->and($this->source->calls)
        ->toBeEmpty();
})->with([false, true]);

it('uses the active Route guard when removal sends an empty JSON body', function (): void {
    $created = $this->postJson('/api/v1/instances', [
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ])->assertCreated();
    $this->source->calls = [];

    $response = $this->deleteJson("/api/v1/instances/{$created->json('data.id')}");

    $response
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.reconciliation_required');
    expect(AppInstance::query()->count())
        ->toBe(1)
        ->and($this->source->calls)
        ->toBeEmpty();
});

it('checks the active Route before checkout overlap during removal', function (): void {
    $created = $this->postJson('/api/v1/instances', [
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ])->assertCreated();
    $this->source->calls = [];

    $this
        ->deleteJson("/api/v1/instances/{$created->json('data.id')}")
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.reconciliation_required');

    expect(AppInstance::query()->count())
        ->toBe(1)
        ->and($this->source->calls)
        ->toBeEmpty();
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
