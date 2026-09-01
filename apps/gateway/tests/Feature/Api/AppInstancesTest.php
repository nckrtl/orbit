<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\DevelopmentAppInstanceSourceLifecycle;
use App\Domain\AppInstances\DevelopmentSourceResolution;
use App\Domain\Clusters\ClusterState;
use App\Domain\Instances\CertificateMode;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Workspace;

beforeEach(function (): void {
    app()->instance(ManagedUserAccountResolver::class, new class implements ManagedUserAccountResolver {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
        }
    });
    $this->source = new class implements DevelopmentAppInstanceSourceLifecycle {
        /** @var list<string> */
        public array $calls = [];

        public ?string $fail = null;

        public DevelopmentSourceResolution $resolution;

        public function __construct()
        {
            $this->resolution = new DevelopmentSourceResolution('dev', str_repeat('a', 40));
        }

        public function prepare(AppInstance $appInstance): void
        {
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

    $this->cluster = Cluster::query()->create([
        'name' => 'development',
        'state' => ClusterState::Active,
    ]);
    $this->node = Node::query()->create([
        'cluster_id' => $this->cluster->id,
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
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

it('creates an active AppInstance with immutable placement and inherited root', function (): void {
    $response = $this->postJson('/api/v1/instances', [
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.cluster_id', $this->cluster->id)
        ->assertJsonPath('data.checkout_path', '/srv/orbit/apps/acme/dev')
        ->assertJsonPath('data.root', null)
        ->assertJsonPath('data.effective_root', 'public')
        ->assertJsonPath('data.branch', 'dev')
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
        ]);
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
})->with([
    'reserved' => ['prepare', AppInstanceState::Reserved],
    'checkout prepared' => ['resolve', AppInstanceState::CheckoutPrepared],
    'source resolved' => ['inspect-resolved', AppInstanceState::SourceResolved],
]);

it('rejects a retry on another Cluster before source work or state mutation', function (): void {
    $this->postJson('/api/v1/instances', [
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ])->assertCreated();
    $otherCluster = Cluster::query()->create(['name' => 'other', 'state' => ClusterState::Active]);
    $otherNode = Node::query()->create([
        'cluster_id' => $otherCluster->id,
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

it('rejects inactive Cluster Node role and platform placement before mutation', function (string $invalid): void {
    if ($invalid === 'cluster') {
        $this->cluster->update(['state' => ClusterState::Inactive]);
    } elseif ($invalid === 'node') {
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
})->with(['cluster', 'node', 'role', 'platform']);

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

it('rejects immutable root and remote evidence conflicts on retry', function (string $conflict): void {
    $payload = [
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ];
    $this->postJson('/api/v1/instances', $payload)->assertCreated();
    $before = AppInstance::query()->sole()->getAttributes();
    $this->source->calls = [];

    if ($conflict === 'root') {
        $payload['root'] = 'other/public';
    } else {
        $this->source->resolution = new DevelopmentSourceResolution('dev', str_repeat('b', 40));
    }

    $this
        ->postJson('/api/v1/instances', $payload)
        ->assertConflict();

    expect(AppInstance::query()->sole()->getAttributes())->toBe($before);

    if ($conflict === 'root') {
        expect($this->source->calls)->toBeEmpty();
    }
})->with(['root', 'commit']);

it('rejects repository execution and unsupported transport keys', function (): void {
    $this
        ->postJson('/api/v1/instances', [
            'app_id' => $this->orbitApp->id,
            'node_id' => $this->node->id,
            'name' => 'dev',
            'repository_url' => 'https://github.com/acme/other.git',
            'command' => 'id',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');

    expect(AppInstance::query()->count())
        ->toBe(0)
        ->and($this->source->calls)
        ->toBeEmpty();
});

it('removes through the source-only lifecycle and transports discard intent', function (bool $discard): void {
    $created = $this->postJson('/api/v1/instances', [
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ])->assertCreated();
    $this->source->calls = [];

    $this
        ->deleteJson("/api/v1/instances/{$created->json('data.id')}", [
            'discard_source' => $discard,
        ])
        ->assertOk()
        ->assertJsonPath('data.id', $created->json('data.id'));

    expect(AppInstance::query()->count())
        ->toBe(0)
        ->and($this->source->calls)
        ->toBe([$discard ? 'remove-discard:active' : 'remove:active']);
})->with([false, true]);

it('uses normal source checks when removal sends an empty JSON body', function (): void {
    $created = $this->postJson('/api/v1/instances', [
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ])->assertCreated();
    $this->source->calls = [];

    $response = $this->deleteJson("/api/v1/instances/{$created->json('data.id')}");

    $response
        ->assertOk()
        ->assertJsonPath('data.id', $created->json('data.id'));
    expect(AppInstance::query()->count())
        ->toBe(0)
        ->and($this->source->calls)
        ->toBe(['remove:active']);
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
