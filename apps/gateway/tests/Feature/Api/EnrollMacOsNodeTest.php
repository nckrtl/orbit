<?php

declare(strict_types=1);

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Metrics\MetricsAccessRevoker;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Nodes\NodeAgentRuntime;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\GatewayPeerProjectionManager;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\HostKeyScanner;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Orbit\Sdk\Requests\Nodes\AddNodeRequest;
use Orbit\Sdk\Requests\Nodes\RemoveNodeRequest;
use Tests\Support\FakeNodeAgentRuntime;

beforeEach(function (): void {
    app()->instance(SshExecutor::class, new class implements SshExecutor
    {
        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            if ($command->arguments[0] === 'true') {
                return new CommandResult(0, '', '', 1, false);
            }

            return new CommandResult(0, "Darwin\narm64\nok\n1\n", '', 1, false);
        }
    });
    app()->instance(HostKeyScanner::class, new class implements HostKeyScanner
    {
        public function scan(string $host, int $port, ?SshConnection $via = null): HostKey
        {
            return new HostKey('ssh-ed25519', 'PUBLICKEY', 'SHA256:'.str_repeat('M', 43));
        }
    });
    app()->instance(SshKeyProvider::class, new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/tmp/orbit-gateway-key';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 GATEWAY';
        }
    });
    app()->instance(KnownHostsStore::class, new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/tmp/orbit-test-known-hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    });
    app()->instance(NodeAgentRuntime::class, new FakeNodeAgentRuntime);
    app()->instance(NodeRoleFirewallManager::class, new class implements NodeRoleFirewallManager
    {
        public function convergeBase(Node $node, string $managedUser): void {}

        public function converge(Node $node, RoleName $role, string $managedUser): void {}

        public function remove(Node $node, RoleName $role, string $managedUser): void {}

        public function trustWireGuardMembers(Node $node, string $managedUser): void {}

        public function restorePublicSsh(Node $node, string $managedUser): void {}
    });
    app()->instance(GatewayPeerProjectionManager::class, new class implements GatewayPeerProjectionManager
    {
        public function converge(Node $node): void {}

        public function remove(Node $node): void {}

        public function restore(Node $node): void {}
    });
    app()->instance(PrivateDnsManager::class, new class implements PrivateDnsManager
    {
        public function converge(?Node $pendingNode = null): void {}
    });
    app()->instance(MetricsAccessRevoker::class, new class implements MetricsAccessRevoker
    {
        public function revoke(): void {}
    });
    app()->instance(MetricsFleetReconciler::class, new class implements MetricsFleetReconciler
    {
        public function reconcile(): void {}

        public function retire(Node $node): void {}
    });

    $operator = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.2',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.1',
        'ssh_host_fingerprint' => 'SHA256:'.str_repeat('G', 43),
    ]);
    $this->markAsGateway($operator);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.1']);
    $this->withHeader('X-Orbit-Request-Id', fixture_request_id());
});

it('records an enrolled mac and its removal', function (): void {
    $created = $this->postJson('/api/v1/nodes', [
        'name' => 'mini',
        'public_ssh_host' => '192.0.2.40',
        'platform' => 'macos',
        'user' => 'mini',
        'orbit_user' => 'mini',
        'wireguard_ip' => '10.44.0.40',
        'host_key_fingerprint' => 'SHA256:'.str_repeat('M', 43),
    ])->assertCreated()
        ->assertJsonPath('data.id', 2)
        ->assertJsonPath('data.platform', 'macos')
        ->assertJsonPath('data.architecture', 'arm64')
        ->assertJsonPath('data.user', 'mini')
        ->assertJsonPath('data.roles', [])
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.ssh_host_fingerprint', 'SHA256:'.str_repeat('M', 43));

    record_fixture($created, 'nodes/node-add/macos-enrolled', AddNodeRequest::class, 'POST /api/v1/nodes');

    Node::query()->findOrFail(2)->update(['wireguard_public_key' => 'MINI_KEY']);

    $removed = $this->deleteJson('/api/v1/nodes/2', ['offline' => false])->assertOk()
        ->assertJsonPath('data.removed', true)
        ->assertJsonPath('data.wireguard_peer_removed', true)
        ->assertJsonPath('data.retained_on_node', ['user', 'package-managers', 'host-wireguard'])
        ->assertJsonPath('data.follow_up', null);

    record_fixture($removed, 'nodes/node-remove/macos-removed', RemoveNodeRequest::class, 'DELETE /api/v1/nodes/{node}');
});

it('rejects mac enrollment that omits the account, mixes accounts, or requests a role', function (): void {
    $fingerprint = 'SHA256:'.str_repeat('M', 43);
    $account = [
        'name' => 'mini',
        'public_ssh_host' => '192.0.2.40',
        'platform' => 'macos',
        'user' => 'mini',
        'orbit_user' => 'mini',
        'wireguard_ip' => '10.44.0.40',
        'host_key_fingerprint' => $fingerprint,
    ];

    $missingAccount = $this->postJson('/api/v1/nodes', [
        'name' => 'mini',
        'public_ssh_host' => '192.0.2.40',
        'platform' => 'macos',
        'wireguard_ip' => '10.44.0.40',
        'host_key_fingerprint' => $fingerprint,
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'node.macos_account_required')
        ->assertJsonPath('error.message', 'macOS enrollment requires the existing account in user and orbit_user.');
    record_fixture($missingAccount, 'nodes/node-add/macos-account-required', AddNodeRequest::class, 'POST /api/v1/nodes');

    $mismatch = $this->postJson('/api/v1/nodes', [
        ...$account,
        'orbit_user' => 'other',
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'node.macos_account_mismatch')
        ->assertJsonPath('error.message', 'macOS enrollment requires user and orbit_user to name the same account.');
    record_fixture($mismatch, 'nodes/node-add/macos-account-mismatch', AddNodeRequest::class, 'POST /api/v1/nodes');

    $role = $this->postJson('/api/v1/nodes', [
        ...$account,
        'roles' => ['app-dev'],
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'node.platform_unsupported')
        ->assertJsonPath('error.message', 'Node platform [macos] does not support service roles.');
    record_fixture($role, 'nodes/node-add/macos-role-unsupported', AddNodeRequest::class, 'POST /api/v1/nodes');

    $settings = $this->postJson('/api/v1/nodes', [
        ...$account,
        'dns_server_override' => '10.0.0.2',
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'node.platform_unsupported')
        ->assertJsonPath('error.message', 'macOS enrollment does not change Cluster, DNS, tunnel, or storage settings.');
    record_fixture($settings, 'nodes/node-add/macos-settings-unsupported', AddNodeRequest::class, 'POST /api/v1/nodes');

    $wireguard = $this->postJson('/api/v1/nodes', [
        'name' => 'mini',
        'public_ssh_host' => '192.0.2.40',
        'platform' => 'macos',
        'user' => 'mini',
        'orbit_user' => 'mini',
        'host_key_fingerprint' => $fingerprint,
    ])->assertConflict()
        ->assertJsonPath('error.code', 'node.wireguard_required')
        ->assertJsonPath('error.message', 'macOS enrollment requires a WireGuard address that is already on the machine.')
        ->assertJsonPath('error.details.step', 'identity');
    record_fixture($wireguard, 'nodes/node-add/macos-wireguard-required', AddNodeRequest::class, 'POST /api/v1/nodes');

    expect(Node::query()->where('name', 'mini')->exists())->toBeFalse();
});
