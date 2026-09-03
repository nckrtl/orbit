<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceSourceKind;
use App\Domain\AppInstances\RegisteredWorktreeInspector;
use App\Domain\AppInstances\RegisteredWorktreeObservation;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;

beforeEach(function (): void {
    $this->caller = Node::query()->create([
        'name' => 'caller-app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.10',
        'wireguard_ip' => '10.44.0.10',
        'user' => 'orbit',
    ]);
    $this->caller
        ->roles()
        ->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.10']);
    $this->orbitApp = OrbitApp::query()->create([
        'name' => 'Example',
        'slug' => 'example',
        'repository_url' => 'https://github.com/acme/example.git',
        'main_branch' => 'main',
        'root' => 'public',
    ]);
    $this->worktree = new class implements RegisteredWorktreeInspector {
        public RegisteredWorktreeObservation $observation;

        public bool $fail = false;

        /** @var list<array{node: int, app: int, checkout: string, root: string}> */
        public array $calls = [];

        public function __construct()
        {
            $this->observation = new RegisteredWorktreeObservation(
                checkoutPath: '/home/orbit/.codex/worktrees/dfb5/example',
                branch: null,
                startingCommit: str_repeat('a', 40),
                sourceIdentity: str_repeat('b', 64),
            );
        }

        public function inspect(
            Node $node,
            OrbitApp $app,
            string $checkoutPath,
            string $effectiveRoot,
        ): RegisteredWorktreeObservation {
            if ($this->fail) {
                throw new ResourceOperationException('instance.worktree_invalid', 'Registered worktree is invalid.');
            }

            $this->calls[] = [
                'node' => $node->id,
                'app' => $app->id,
                'checkout' => $checkoutPath,
                'root' => $effectiveRoot,
            ];

            return $this->observation;
        }
    };
    app()->instance(RegisteredWorktreeInspector::class, $this->worktree);
});

it('registers the exact caller-local worktree without storing Cluster ownership', function (): void {
    $this
        ->postJson('/api/v1/instances/register', [
            'app' => 'example',
            'checkout_path' => '/home/orbit/.codex/worktrees/dfb5/example',
        ])
        ->assertCreated()
        ->assertJsonPath('data.node_id', $this->caller->id)
        ->assertJsonPath('data.cluster_id', null)
        ->assertJsonPath('data.name', 'dfb5')
        ->assertJsonPath('data.source_kind', AppInstanceSourceKind::RegisteredWorktree->value)
        ->assertJsonPath('data.checkout_path', '/home/orbit/.codex/worktrees/dfb5/example')
        ->assertJsonPath('data.root', null)
        ->assertJsonPath('data.effective_root', 'public')
        ->assertJsonPath('data.selected_branch', null)
        ->assertJsonPath('data.starting_commit', str_repeat('a', 40))
        ->assertJsonPath('data.status', 'active');

    $stored = AppInstance::query()->sole();

    expect($stored->source_identity)
        ->toBe(str_repeat('b', 64))
        ->and($this->worktree->calls)
        ->toBe([[
            'node' => $this->caller->id,
            'app' => $this->orbitApp->id,
            'checkout' => '/home/orbit/.codex/worktrees/dfb5/example',
            'root' => 'public',
        ]]);
});

it('re-verifies retries while preserving the initial branch and commit observations', function (): void {
    $payload = [
        'app' => 'example',
        'checkout_path' => '/home/orbit/.codex/worktrees/dfb5/example',
        'name' => 'feature',
        'root' => 'site/public',
    ];
    $this->postJson('/api/v1/instances/register', $payload)->assertCreated();
    $this->worktree->observation = new RegisteredWorktreeObservation(
        checkoutPath: '/home/orbit/.codex/worktrees/dfb5/example',
        branch: 'later-branch',
        startingCommit: str_repeat('c', 40),
        sourceIdentity: str_repeat('b', 64),
    );

    $this
        ->postJson('/api/v1/instances/register', $payload)
        ->assertOk()
        ->assertJsonPath('data.selected_branch', null)
        ->assertJsonPath('data.starting_commit', str_repeat('a', 40));

    expect(AppInstance::query()->count())->toBe(1);
});

it('rejects caller, path, root, and identity conflicts without creating or rewriting a record', function (): void {
    $payload = [
        'app' => 'example',
        'checkout_path' => '/home/orbit/.codex/worktrees/dfb5/example',
        'name' => 'dfb5',
    ];
    $this->postJson('/api/v1/instances/register', $payload)->assertCreated();
    $before = AppInstance::query()->sole()->getAttributes();
    $this->worktree->observation = new RegisteredWorktreeObservation(
        checkoutPath: '/home/orbit/.codex/worktrees/other/example',
        branch: null,
        startingCommit: str_repeat('a', 40),
        sourceIdentity: str_repeat('d', 64),
    );

    $this
        ->postJson('/api/v1/instances/register', $payload)
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.worktree_identity_invalid');
    $this
        ->postJson('/api/v1/instances/register', [...$payload, 'node_id' => $this->caller->id])
        ->assertUnprocessable();
    $this
        ->postJson('/api/v1/instances/register', [...$payload, 'root' => '../public'])
        ->assertUnprocessable();

    expect(AppInstance::query()->sole()->getAttributes())->toBe($before);
});

it('rejects a role-less caller before inspection and mutation', function (): void {
    $this->caller->roles()->delete();

    $this
        ->postJson('/api/v1/instances/register', [
            'app' => 'example',
            'checkout_path' => '/home/orbit/.codex/worktrees/dfb5/example',
            'name' => 'dfb5',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.caller_not_app_dev');

    expect(AppInstance::query()->count())
        ->toBe(0)
        ->and($this->worktree->calls)
        ->toBeEmpty();
});

it('leaves no durable row when read-only worktree verification fails', function (): void {
    $this->worktree->fail = true;

    $this
        ->postJson('/api/v1/instances/register', [
            'app' => 'example',
            'checkout_path' => '/home/orbit/.codex/worktrees/dfb5/example',
            'name' => 'dfb5',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'instance.worktree_invalid');

    expect(AppInstance::query()->count())->toBe(0);
});

it('rejects an invalid derived name and checkout overlap before registration mutation', function (): void {
    $this
        ->postJson('/api/v1/instances/register', [
            'app' => 'example',
            'checkout_path' => '/home/orbit/.codex/worktrees/INVALID_NAME/example',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'instance.name_invalid');

    AppInstance::query()->create([
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->caller->id,
        'name' => 'managed',
        'source_kind' => AppInstanceSourceKind::ManagedClone->value,
        'checkout_path' => '/home/orbit/.codex/worktrees',
        'status' => 'active',
    ]);

    $this
        ->postJson('/api/v1/instances/register', [
            'app' => 'example',
            'checkout_path' => '/home/orbit/.codex/worktrees/dfb5/example',
            'name' => 'dfb5',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.path_taken');

    expect(AppInstance::query()->count())->toBe(1);
});

it('unregisters only registered worktrees without touching external source', function (): void {
    $registered = $this
        ->postJson('/api/v1/instances/register', [
            'app' => 'example',
            'checkout_path' => '/home/orbit/.codex/worktrees/dfb5/example',
            'name' => 'dfb5',
        ])
        ->assertCreated()
        ->json('data.id');

    $this
        ->deleteJson("/api/v1/instances/{$registered}/registration")
        ->assertOk()
        ->assertJsonPath('data.source_kind', AppInstanceSourceKind::RegisteredWorktree->value);

    expect(AppInstance::query()->count())->toBe(0);

    $managed = AppInstance::query()->create([
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->caller->id,
        'name' => 'managed',
        'checkout_path' => '/home/orbit/apps/example/managed',
        'source_kind' => AppInstanceSourceKind::ManagedClone->value,
        'status' => 'active',
    ]);

    $this
        ->deleteJson("/api/v1/instances/{$managed->id}/registration")
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.source_kind_conflict');

    expect($managed->fresh())->not->toBeNull();
});
