<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
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

            public function removeUnreachable(Node $node, NodeRole $assignment): void {}
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
            'wireguard_ip' => '10.44.0.2',
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
                    'apps' => ['path' => '/srv/orbit/apps'],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.settings.apps.path', '/srv/orbit/apps');

        $node = Node::query()->where('name', 'app-dev')->sole();

        expect($node->settings)
            ->toBe([
                'apps' => ['path' => '/srv/orbit/apps'],
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

    it('patches and unsets apps while preserving legacy stored settings', function (): void {
        $node = Node::query()->create([
            'name' => 'app-dev',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.3',
            'settings' => [
                'apps' => ['path' => '/srv/orbit/apps'],
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
                'apps' => ['path' => '/mnt/apps'],
            ])
            ->assertOk()
            ->assertJsonPath('data.settings.apps.path', '/mnt/apps')
            ->assertJsonMissingPath('data.settings.instance')
            ->assertJsonMissingPath('data.settings.worktree');

        expect($node->refresh()->settings)->toBe([
            'instance' => ['path' => '/srv/orbit/instances'],
            'worktree' => ['path' => '/srv/orbit/worktrees'],
            'apps' => ['path' => '/mnt/apps'],
        ]);

        $this
            ->patchJson("/api/v1/nodes/{$node->id}/settings", [
                'apps' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.settings', null);

        expect($node->refresh()->settings)->toBe([
            'instance' => ['path' => '/srv/orbit/instances'],
            'worktree' => ['path' => '/srv/orbit/worktrees'],
        ]);
    });

    it('rejects unknown settings keys without persisting', function (): void {
        $node = Node::query()->create([
            'name' => 'app-dev',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.3',
        ]);

        $this
            ->patchJson("/api/v1/nodes/{$node->id}/settings", [
                'packages' => ['path' => '/srv/orbit/packages'],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'node.settings_invalid');

        expect($node->refresh()->settings)->toBeNull();
    });

    it('rejects an apps root that overlaps the legacy worktree root without persisting', function (): void {
        $node = Node::query()->create([
            'name' => 'app-dev',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.3',
            'settings' => [
                'worktree' => ['path' => '/srv/orbit/source/worktrees'],
            ],
        ]);
        $node->roles()->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);

        $this
            ->patchJson("/api/v1/nodes/{$node->id}/settings", [
                'apps' => ['path' => '/srv/orbit/source'],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'node.settings_roots_overlap');

        expect($node->refresh()->settings)->toBe([
            'worktree' => ['path' => '/srv/orbit/source/worktrees'],
        ]);
    });

    it('rejects protected roots on a node without app-dev before persisting', function (): void {
        $inspected = [];
        $prepared = [];
        app()->instance(NodeStorageRootPreparer::class, recording_storage_preparer($inspected, $prepared));
        $node = Node::query()->create([
            'name' => 'gateway',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.1',
        ]);

        $this
            ->patchJson("/api/v1/nodes/{$node->id}/settings", [
                'apps' => ['path' => '/etc/orbit'],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'node.settings_path_protected');

        expect($node->refresh()->settings)
            ->toBeNull()
            ->and($prepared)
            ->toBe([]);
    });

    it('inspects explicit roots on a node without app-dev and does not create them', function (): void {
        $inspected = [];
        $prepared = [];
        app()->instance(NodeStorageRootPreparer::class, recording_storage_preparer($inspected, $prepared));
        $node = Node::query()->create([
            'name' => 'gateway',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.1',
        ]);

        $this
            ->patchJson("/api/v1/nodes/{$node->id}/settings", [
                'apps' => ['path' => '/srv/orbit/apps'],
            ])
            ->assertOk()
            ->assertJsonPath('data.settings.apps.path', '/srv/orbit/apps');

        expect($node->refresh()->settings)
            ->toBe([
                'apps' => ['path' => '/srv/orbit/apps'],
            ])
            ->and($inspected)
            ->toBe(['/srv/orbit/apps'])
            ->and($prepared)
            ->toBe([]);
    });

    it('rejects an empty nested path instead of treating it as an unset', function (): void {
        $node = Node::query()->create([
            'name' => 'app-dev',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.3',
            'settings' => [
                'apps' => ['path' => '/srv/orbit/apps'],
            ],
        ]);
        $node->roles()->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);

        $this
            ->patchJson("/api/v1/nodes/{$node->id}/settings", [
                'apps' => ['path' => ''],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'node.settings_path_invalid');

        expect($node->refresh()->settings)
            ->toBe([
                'apps' => ['path' => '/srv/orbit/apps'],
            ]);
    });

    it('rejects roots that overlap the configured Gateway checkout', function (string $path): void {
        config()->set('orbit.gateway_checkout', '/srv/orbit-gateway');
        $node = Node::query()->create([
            'name' => 'app-dev',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.3',
        ]);
        $node->roles()->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);

        $this
            ->patchJson("/api/v1/nodes/{$node->id}/settings", [
                'apps' => ['path' => $path],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'node.settings_path_protected');

        expect($node->refresh()->settings)->toBeNull();
    })->with([
        'equal' => '/srv/orbit-gateway',
        'inside' => '/srv/orbit-gateway/app',
        'contains' => '/srv',
    ]);

    it('accepts a sibling of the configured Gateway checkout', function (): void {
        config()->set('orbit.gateway_checkout', '/srv/orbit-gateway');
        $inspected = [];
        $prepared = [];
        app()->instance(NodeStorageRootPreparer::class, recording_storage_preparer($inspected, $prepared));
        $node = Node::query()->create([
            'name' => 'app-dev',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.3',
        ]);
        $node->roles()->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);

        $this
            ->patchJson("/api/v1/nodes/{$node->id}/settings", [
                'apps' => ['path' => '/srv/orbit-apps'],
            ])
            ->assertOk()
            ->assertJsonPath('data.settings.apps.path', '/srv/orbit-apps');

        expect($node->refresh()->settings)
            ->toBe([
                'apps' => ['path' => '/srv/orbit-apps'],
            ]);
    });

    it('rejects an apps root inside the worktree default hidden control path', function (): void {
        $node = Node::query()->create([
            'name' => 'app-dev',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.3',
            'settings' => [
                'worktree' => ['path' => '/srv/orbit/worktrees'],
            ],
        ]);
        $node->roles()->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);

        $this
            ->patchJson("/api/v1/nodes/{$node->id}/settings", [
                'apps' => ['path' => '/home/orbit/.orbit/worktrees/instances'],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'node.settings_path_protected');

        expect($node->refresh()->settings)->toBe([
            'worktree' => ['path' => '/srv/orbit/worktrees'],
        ]);
    });

    it('preserves the exact legacy worktree default while updating apps', function (): void {
        $inspected = [];
        $prepared = [];
        app()->instance(NodeStorageRootPreparer::class, recording_storage_preparer($inspected, $prepared));
        $node = Node::query()->create([
            'name' => 'app-dev',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.3',
            'settings' => [
                'worktree' => ['path' => '/home/orbit/.orbit/worktrees'],
            ],
        ]);
        $node->roles()->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);

        $this
            ->patchJson("/api/v1/nodes/{$node->id}/settings", [
                'apps' => ['path' => '/srv/orbit/apps'],
            ])
            ->assertOk()
            ->assertJsonPath('data.settings.apps.path', '/srv/orbit/apps');

        expect($node->refresh()->settings)
            ->toBe([
                'worktree' => ['path' => '/home/orbit/.orbit/worktrees'],
                'apps' => ['path' => '/srv/orbit/apps'],
            ]);
    });

    it('rejects an apps root that is a descendant of the worktree default', function (): void {
        $node = Node::query()->create([
            'name' => 'app-dev',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.3',
            'settings' => [
                'worktree' => ['path' => '/srv/orbit/worktrees'],
            ],
        ]);
        $node->roles()->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);

        $this
            ->patchJson("/api/v1/nodes/{$node->id}/settings", [
                'apps' => ['path' => '/home/orbit/.orbit/worktrees/extra'],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'node.settings_path_protected');

        expect($node->refresh()->settings)->toBe([
            'worktree' => ['path' => '/srv/orbit/worktrees'],
        ]);
    });

    it('falls back to the legacy instance path before the managed-home apps default', function (): void {
        $inspected = [];
        $prepared = [];
        app()->instance(NodeStorageRootPreparer::class, recording_storage_preparer($inspected, $prepared));
        $node = Node::query()->create([
            'name' => 'app-dev',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.3',
            'settings' => [
                'apps' => ['path' => '/srv/orbit/apps'],
                'instance' => ['path' => '/srv/orbit/instances'],
            ],
        ]);
        $node->roles()->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);

        $this
            ->patchJson("/api/v1/nodes/{$node->id}/settings", [
                'apps' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.settings', null);

        expect($node->refresh()->settings)
            ->toBe([
                'instance' => ['path' => '/srv/orbit/instances'],
            ])
            ->and($inspected)
            ->toBe([])
            ->and($prepared)
            ->toBe(['/srv/orbit/instances', '/home/orbit/.orbit/worktrees']);
    });

    it('leaves stored settings unchanged when preparing defaults for the last unset fails', function (): void {
        app()->instance(NodeStorageRootPreparer::class, new class implements NodeStorageRootPreparer {
            public function inspect(Node $node, ManagedUserAccount $account, StoragePath $path): void {}

            public function prepare(Node $node, ManagedUserAccount $account, EffectiveStorageRoots $roots): void
            {
                throw new RuntimeConvergenceException(
                    step: 'node-storage-root',
                    errorCode: 'node.settings_root_failed',
                    message: 'Storage root failed.',
                );
            }
        });
        $node = Node::query()->create([
            'name' => 'app-dev',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.3',
            'settings' => [
                'apps' => ['path' => '/srv/orbit/apps'],
                'worktree' => ['path' => '/srv/orbit/worktrees'],
            ],
        ]);
        $node->roles()->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);

        $this
            ->patchJson("/api/v1/nodes/{$node->id}/settings", [
                'apps' => null,
            ])
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'node.settings_root_failed');

        expect($node->refresh()->settings)
            ->toBe([
                'apps' => ['path' => '/srv/orbit/apps'],
                'worktree' => ['path' => '/srv/orbit/worktrees'],
            ]);
    });

    it('rejects list settings at every HTTP boundary without changing stored intent', function (
        string $method,
        string $path,
        array $payload,
        ?array $stored,
    ): void {
        $node = Node::query()->create([
            'name' => 'app-dev',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.3',
            'settings' => $stored,
        ]);
        $node->roles()->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);
        $url = $path === 'settings' ? "/api/v1/nodes/{$node->id}/settings" : '/api/v1/nodes';

        $this
            ->json($method, $url, $payload)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'node.settings_invalid');

        expect($node->refresh()->settings)->toBe($stored);
        if ($path === 'provision') {
            expect(Node::query()->where('name', 'listed')->exists())->toBeFalse();
        }
    })->with([
        'patch list body' => [
            'PATCH',
            'settings',
            [],
            ['apps' => ['path' => '/srv/orbit/apps']],
        ],
        'patch nested list' => [
            'PATCH',
            'settings',
            ['apps' => []],
            ['apps' => ['path' => '/srv/orbit/apps']],
        ],
        'provision list settings' => [
            'POST',
            'provision',
            [
                'name' => 'listed',
                'public_ssh_host' => '192.0.2.11',
                'platform' => 'linux',
                'architecture' => 'x86_64',
                'tld' => 'dev.orbit',
                'roles' => ['app-dev'],
                'host_key_fingerprint' => 'SHA256:'.str_repeat('A', 43),
                'settings' => [],
            ],
            ['instance' => ['path' => '/srv/orbit/instances']],
        ],
        'provision nested list' => [
            'POST',
            'provision',
            [
                'name' => 'listed',
                'public_ssh_host' => '192.0.2.11',
                'platform' => 'linux',
                'architecture' => 'x86_64',
                'tld' => 'dev.orbit',
                'roles' => ['app-dev'],
                'host_key_fingerprint' => 'SHA256:'.str_repeat('A', 43),
                'settings' => ['apps' => []],
            ],
            ['instance' => ['path' => '/srv/orbit/instances']],
        ],
    ]);
});

function recording_storage_preparer(array &$inspected, array &$prepared): NodeStorageRootPreparer
{
    return new class($inspected, $prepared) implements NodeStorageRootPreparer {
        /**
         * @param list<string> $inspected
         * @param list<string> $prepared
         */
        public function __construct(
            private array &$inspected,
            private array &$prepared,
        ) {}

        public function inspect(Node $node, ManagedUserAccount $account, StoragePath $path): void
        {
            $this->inspected[] = $path->value;
        }

        public function prepare(Node $node, ManagedUserAccount $account, EffectiveStorageRoots $roots): void
        {
            $this->prepared[] = $roots->instance->value;
            $this->prepared[] = $roots->worktree->value;
        }
    };
}
