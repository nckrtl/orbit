<?php

declare(strict_types=1);

use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeConverger;
use App\Domain\Nodes\NodeProvisioningIdentity;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\EffectiveStorageRoots;
use App\Domain\Nodes\Storage\NodeStorageRootPreparer;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolManagerMaterializer;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Support\Str;
use Tests\Support\FakeToolManagerMaterializer;

function fake_storage_preparer(): NodeStorageRootPreparer
{
    return new class implements NodeStorageRootPreparer {
        public function inspect(Node $node, ManagedUserAccount $account, StoragePath $path): void {}

        public function prepare(Node $node, ManagedUserAccount $account, EffectiveStorageRoots $roots): void {}
    };
}

describe('node storage settings', function (): void {
    beforeEach(function (): void {
        app()->instance(ToolManagerMaterializer::class, new FakeToolManagerMaterializer);
        app()->instance(RoleBaselineConverger::class, new class implements RoleBaselineConverger {
            public function converge(Node $node, NodeRole $assignment): void {}

            public function remove(Node $node, NodeRole $assignment, bool $purgeData): void {}
        });
        app()->instance(NodeConverger::class, new class implements NodeConverger {
            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): void {}
        });
        app()->instance(NodeStorageRootPreparer::class, fake_storage_preparer());
        app()->instance(ManagedUserAccountResolver::class, new class implements ManagedUserAccountResolver {
            public function resolve(Node $node): ManagedUserAccount
            {
                return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
            }
        });
        $this->operator = Node::query()->create([
            'name' => 'operator',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.2',
            'wireguard_address' => '10.44.0.2',
        ]);
        $this->markAsGateway($this->operator);
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    });

    it('provisions typed raw settings and returns them without substituting defaults', function (): void {
        $requestId = (string) Str::uuid();

        $this
            ->withHeader('X-Orbit-Request-Id', $requestId)
            ->postJson('/api/v1/nodes', [
                'name' => 'app-dev',
                'public_ssh_host' => '192.0.2.10',
                'platform' => 'linux',
                'architecture' => 'x86_64',
                'tld' => 'dev.orbit',
                'roles' => ['app-dev'],
                'host_key_fingerprint' => 'SHA256:'.str_repeat('A', 43),
                'settings' => [
                    'instance' => ['path' => '/srv/orbit/instances'],
                    'worktree' => ['path' => '/srv/orbit/worktrees'],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.settings.instance.path', '/srv/orbit/instances')
            ->assertJsonPath('data.settings.worktree.path', '/srv/orbit/worktrees');

        $node = Node::query()->where('name', 'app-dev')->sole();

        expect($node->settings)
            ->toBe([
                'instance' => ['path' => '/srv/orbit/instances'],
                'worktree' => ['path' => '/srv/orbit/worktrees'],
            ]);
    });

    it('provisions without settings as SQL null and a null response member', function (): void {
        $this
            ->postJson('/api/v1/nodes', [
                'name' => 'app-dev',
                'public_ssh_host' => '192.0.2.10',
                'platform' => 'linux',
                'architecture' => 'x86_64',
                'tld' => 'dev.orbit',
                'roles' => ['app-dev'],
                'host_key_fingerprint' => 'SHA256:'.str_repeat('A', 43),
            ])
            ->assertCreated()
            ->assertJsonPath('data.settings', null);

        expect(Node::query()->where('name', 'app-dev')->sole()->settings)->toBeNull();
    });

    it('patches one setting, preserves the omitted member, and unsets to SQL null', function (): void {
        $node = Node::query()->create([
            'name' => 'app-dev',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'user' => 'orbit',
            'wireguard_address' => '10.44.0.3',
            'settings' => [
                'instance' => ['path' => '/srv/orbit/instances'],
                'worktree' => ['path' => '/srv/orbit/worktrees'],
            ],
        ]);
        $node->roles()->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);

        $this
            ->patchJson("/api/v1/nodes/{$node->id}/settings", [
                'instance' => ['path' => '/mnt/apps'],
            ])
            ->assertOk()
            ->assertJsonPath('data.settings.instance.path', '/mnt/apps')
            ->assertJsonPath('data.settings.worktree.path', '/srv/orbit/worktrees');

        $this
            ->patchJson("/api/v1/nodes/{$node->id}/settings", [
                'instance' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.settings.instance', null)
            ->assertJsonPath('data.settings.worktree.path', '/srv/orbit/worktrees');

        $this
            ->patchJson("/api/v1/nodes/{$node->id}/settings", [
                'worktree' => ['path' => null],
            ])
            ->assertOk()
            ->assertJsonPath('data.settings', null);

        expect($node->refresh()->settings)->toBeNull();
    });

    it('rejects unknown settings keys without persisting', function (): void {
        $node = Node::query()->create([
            'name' => 'app-dev',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'user' => 'orbit',
            'wireguard_address' => '10.44.0.3',
        ]);

        $this
            ->patchJson("/api/v1/nodes/{$node->id}/settings", [
                'packages' => ['path' => '/srv/orbit/packages'],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'node.settings_invalid');

        expect($node->refresh()->settings)->toBeNull();
    });

    it('rejects overlapping configured roots without persisting', function (): void {
        $node = Node::query()->create([
            'name' => 'app-dev',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'user' => 'orbit',
            'wireguard_address' => '10.44.0.3',
        ]);
        $node->roles()->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);

        $this
            ->patchJson("/api/v1/nodes/{$node->id}/settings", [
                'instance' => ['path' => '/srv/orbit/source'],
                'worktree' => ['path' => '/srv/orbit/source/worktrees'],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'node.settings_roots_overlap');

        expect($node->refresh()->settings)->toBeNull();
    });

    it('rejects protected roots on a node without app-dev before persisting', function (): void {
        $prepared = [];
        app()->instance(NodeStorageRootPreparer::class, recording_storage_preparer($prepared));
        $node = Node::query()->create([
            'name' => 'gateway',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'user' => 'orbit',
            'wireguard_address' => '10.44.0.1',
        ]);

        $this
            ->patchJson("/api/v1/nodes/{$node->id}/settings", [
                'instance' => ['path' => '/etc/orbit'],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'node.settings_path_protected');

        expect($node->refresh()->settings)
            ->toBeNull()
            ->and($prepared)
            ->toBe([]);
    });

    it('stores allowed roots on a node without app-dev without preparing them', function (): void {
        $prepared = [];
        app()->instance(NodeStorageRootPreparer::class, recording_storage_preparer($prepared));
        $node = Node::query()->create([
            'name' => 'gateway',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'user' => 'orbit',
            'wireguard_address' => '10.44.0.1',
        ]);

        $this
            ->patchJson("/api/v1/nodes/{$node->id}/settings", [
                'instance' => ['path' => '/srv/orbit/instances'],
                'worktree' => ['path' => '/srv/orbit/worktrees'],
            ])
            ->assertOk()
            ->assertJsonPath('data.settings.instance.path', '/srv/orbit/instances');

        expect($node->refresh()->settings)
            ->toBe([
                'instance' => ['path' => '/srv/orbit/instances'],
                'worktree' => ['path' => '/srv/orbit/worktrees'],
            ])
            ->and($prepared)
            ->toBe([]);
    });
});

function recording_storage_preparer(array &$prepared): NodeStorageRootPreparer
{
    return new class($prepared) implements NodeStorageRootPreparer {
        /** @param list<string> $prepared */
        public function __construct(
            private array &$prepared,
        ) {}

        public function inspect(Node $node, ManagedUserAccount $account, StoragePath $path): void {}

        public function prepare(Node $node, ManagedUserAccount $account, EffectiveStorageRoots $roots): void
        {
            $this->prepared[] = $roots->instance->value;
        }
    };
}
