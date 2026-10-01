<?php

declare(strict_types=1);

use App\Actions\Nodes\RemoveNodeAction;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Metrics\MetricsAccessRevoker;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Nodes\NodeAgentRuntime;
use App\Domain\Nodes\NodeRemovalException;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\GatewayPeerProjectionManager;
use App\Models\Node;
use Tests\Support\FakeNodeAgentRuntime;

beforeEach(function (): void {
    $this->agent = new FakeNodeAgentRuntime;
    $this->firewall = new class implements NodeRoleFirewallManager
    {
        public int $calls = 0;

        public function convergeBase(Node $node, string $managedUser): void
        {
            $this->calls++;
        }

        public function converge(Node $node, RoleName $role, string $managedUser): void
        {
            $this->calls++;
        }

        public function remove(Node $node, RoleName $role, string $managedUser): void
        {
            $this->calls++;
        }

        public function trustWireGuardMembers(Node $node, string $managedUser): void
        {
            $this->calls++;
        }

        public function restorePublicSsh(Node $node, string $managedUser): void
        {
            $this->calls++;
        }
    };
    $this->peers = new class implements GatewayPeerProjectionManager
    {
        /** @var list<int> */
        public array $removed = [];

        public ?Throwable $failure = null;

        public function converge(Node $node): void {}

        public function remove(Node $node): void
        {
            if ($this->failure instanceof Throwable) {
                throw $this->failure;
            }

            $this->removed[] = $node->id;
        }

        public function restore(Node $node): void {}
    };
    app()->instance(NodeAgentRuntime::class, $this->agent);
    app()->instance(NodeRoleFirewallManager::class, $this->firewall);
    app()->instance(GatewayPeerProjectionManager::class, $this->peers);
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
});

it('removes a mac from the registry and hub without linux cleanup', function (): void {
    $caller = mac_removal_node('gateway', '10.44.0.1');
    $mac = mac_removal_node('mini', '10.44.0.40', 'macos', 'mini');
    $mac->update(['wireguard_public_key' => 'MINI_KEY']);

    $result = app(RemoveNodeAction::class)->execute($mac, $caller);

    expect($result->removed)->toBeTrue()
        ->and($result->wireguardPeerRemoved)->toBeTrue()
        ->and($result->retainedOnNode)->toBe(['user', 'package-managers', 'host-wireguard'])
        ->and($result->followUp)->toBeNull()
        ->and($mac->fresh())->toBeNull()
        ->and($this->agent->removedNodeIds)->toBe([])
        ->and($this->firewall->calls)->toBe(0)
        ->and($this->peers->removed)->toBe([$mac->id]);
});

it('restores a mac when hub removal fails and still skips linux cleanup', function (): void {
    $caller = mac_removal_node('gateway', '10.44.0.1');
    $mac = mac_removal_node('mini', '10.44.0.40', 'macos', 'mini');
    $mac->update(['wireguard_public_key' => 'MINI_KEY']);
    $this->peers->failure = new RuntimeException('hub failed');

    expect(fn () => app(RemoveNodeAction::class)->execute($mac, $caller))
        ->toThrow(NodeRemovalException::class);

    expect($mac->fresh()->status)->toBe(LifecycleStatus::Active)
        ->and($mac->fresh()->platform)->toBe('macos')
        ->and($mac->fresh()->user)->toBe('mini')
        ->and($this->agent->removedNodeIds)->toBe([])
        ->and($this->firewall->calls)->toBe(0);
});

function mac_removal_node(string $name, string $address, string $platform = 'linux', string $user = 'orbit'): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => $platform,
        'architecture' => $platform === 'macos' ? 'arm64' : 'x86_64',
        'public_ssh_host' => '192.0.2.'.$name,
        'user' => $user,
        'wireguard_ip' => $address,
        'ssh_host_fingerprint' => 'SHA256:'.$name,
    ]);
}
