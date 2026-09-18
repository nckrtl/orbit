<?php

declare(strict_types=1);

use App\Actions\Nodes\RelocateGatewayRoleAction;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\NodeRoleValidationException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRole;

describe(RelocateGatewayRoleAction::class, function (): void {
    beforeEach(function (): void {
        $this->firewall = new RelocateGatewayFirewallFake;
        $this->dns = new RelocateGatewayDnsFake;
        app()->instance(NodeRoleFirewallManager::class, $this->firewall);
        app()->instance(PrivateDnsManager::class, $this->dns);
    });

    it('transfers the singleton gateway assignment and leaves vpn on the source', function (): void {
        $source = relocate_gateway_node('gateway', '10.44.0.1');
        $target = relocate_gateway_node('beast', '10.44.0.11');
        $assignment = $source->roles()->create([
            'role' => RoleName::Gateway,
            'status' => LifecycleStatus::Active,
        ]);
        $source->roles()->create([
            'role' => RoleName::Vpn,
            'status' => LifecycleStatus::Active,
        ]);
        app(GatewayServingHost::class)->remember($source);

        $result = app(RelocateGatewayRoleAction::class)->execute($target, RoleName::Gateway, force: true);

        expect($result->is($assignment))
            ->toBeTrue()
            ->and($result->node_id)
            ->toBe($target->id)
            ->and($result->status)
            ->toBe(LifecycleStatus::Active)
            ->and(NodeRole::query()->where('role', RoleName::Gateway)->count())
            ->toBe(1)
            ->and($source->roles()->where('role', RoleName::Vpn)->exists())
            ->toBeTrue()
            ->and($source->roles()->where('role', RoleName::Gateway)->exists())
            ->toBeFalse()
            ->and($this->firewall->events)
            ->toBe([
                "converge:gateway:{$target->name}",
                "remove:gateway:{$source->name}",
            ])
            ->and($this->dns->calls)
            ->toBe(1)
            ->and(app(GatewayServingHost::class)->nodeId())
            ->toBe($source->id);
    });

    it('requires force before transferring the assignment', function (): void {
        $source = relocate_gateway_node('gateway', '10.44.0.1');
        $target = relocate_gateway_node('beast', '10.44.0.11');
        $source->roles()->create([
            'role' => RoleName::Gateway,
            'status' => LifecycleStatus::Active,
        ]);

        expect(fn () => app(RelocateGatewayRoleAction::class)->execute($target, RoleName::Gateway))
            ->toThrow(NodeRoleValidationException::class, 'Use --force to relocate this node role.');

        expect($source->roles()->where('role', RoleName::Gateway)->exists())
            ->toBeTrue()
            ->and($this->firewall->events)
            ->toBeEmpty()
            ->and($this->dns->calls)
            ->toBe(0);
    });

    it('refuses roles other than gateway', function (): void {
        $target = relocate_gateway_node('beast', '10.44.0.11');

        expect(fn () => app(RelocateGatewayRoleAction::class)->execute(
            $target,
            RoleName::Vpn,
            force: true,
        ))->toThrow(NodeRoleValidationException::class, 'Role [vpn] cannot be relocated.');
    });

    it('refuses when the target already holds the gateway role', function (): void {
        $target = relocate_gateway_node('beast', '10.44.0.11');
        $target->roles()->create([
            'role' => RoleName::Gateway,
            'status' => LifecycleStatus::Active,
        ]);

        expect(fn () => app(RelocateGatewayRoleAction::class)->execute(
            $target,
            RoleName::Gateway,
            force: true,
        ))->toThrow(NodeRoleValidationException::class, 'Role [gateway] is already assigned to node [beast].');
    });

    it('grants the new gateway access to the vpn and metrics nodes', function (): void {
        $source = relocate_gateway_node('vpn', '10.44.0.1');
        $target = relocate_gateway_node('gateway', '10.44.0.2');
        $metrics = relocate_gateway_node('beast', '10.44.0.9');
        $source->roles()->create([
            'role' => RoleName::Gateway,
            'status' => LifecycleStatus::Active,
        ]);
        $source->roles()->create([
            'role' => RoleName::Vpn,
            'status' => LifecycleStatus::Active,
        ]);
        $metrics->roles()->create([
            'role' => RoleName::Metrics,
            'status' => LifecycleStatus::Active,
        ]);
        app(GatewayServingHost::class)->remember($source);

        app(RelocateGatewayRoleAction::class)->execute($target, RoleName::Gateway, force: true);

        expect(NodeAccess::query()->where('consumer_node_id', $target->id)->pluck('serving_node_id')->all())
            ->toEqualCanonicalizing([$source->id, $metrics->id])
            ->and($this->dns->calls)
            ->toBe(1);
    });

    it('refuses a target that already owns a conflicting role', function (): void {
        $source = relocate_gateway_node('gateway', '10.44.0.1');
        $target = relocate_gateway_node('beast', '10.44.0.11');
        $source->roles()->create([
            'role' => RoleName::Gateway,
            'status' => LifecycleStatus::Active,
        ]);
        $target->roles()->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);

        expect(fn () => app(RelocateGatewayRoleAction::class)->execute(
            $target,
            RoleName::Gateway,
            force: true,
        ))->toThrow(NodeRoleValidationException::class, 'Role [gateway] conflicts with assigned role [app-dev].');

        expect($source->roles()->where('role', RoleName::Gateway)->exists())->toBeTrue();
    });
});

function relocate_gateway_node(string $name, string $wireguardIp): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => $name.'.example.test',
        'user' => 'orbit',
        'wireguard_ip' => $wireguardIp,
    ]);
}

final class RelocateGatewayFirewallFake implements NodeRoleFirewallManager
{
    /** @var list<string> */
    public array $events = [];

    public function convergeBase(Node $node, string $managedUser): void {}

    public function converge(Node $node, RoleName $role, string $managedUser): void
    {
        $this->events[] = "converge:{$role->value}:{$node->name}";
    }

    public function remove(Node $node, RoleName $role, string $managedUser): void
    {
        $this->events[] = "remove:{$role->value}:{$node->name}";
    }

    public function restorePublicSsh(Node $node, string $managedUser): void {}

    public function trustWireGuardMembers(Node $node, string $managedUser): void {}
}

final class RelocateGatewayDnsFake implements PrivateDnsManager
{
    public int $calls = 0;

    public function converge(?Node $pendingNode = null): void
    {
        $this->calls++;
    }
}
