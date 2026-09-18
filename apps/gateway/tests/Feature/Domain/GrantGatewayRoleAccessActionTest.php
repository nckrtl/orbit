<?php

declare(strict_types=1);

use App\Actions\Nodes\GrantGatewayRoleAccessAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\NodeAccess;

it('grants the gateway access to the vpn and metrics nodes', function (): void {
    $gateway = grant_gateway_access_node('gateway', '10.44.0.2', RoleName::Gateway);
    $vpn = grant_gateway_access_node('vpn', '10.44.0.1', RoleName::Vpn);
    $metrics = grant_gateway_access_node('beast', '10.44.0.9', RoleName::Metrics);

    $granted = app(GrantGatewayRoleAccessAction::class)->execute($gateway);

    expect(array_map(static fn (Node $node): int => $node->id, $granted))
        ->toEqualCanonicalizing([$vpn->id, $metrics->id])
        ->and(NodeAccess::query()->where('consumer_node_id', $gateway->id)->pluck('serving_node_id')->all())
        ->toEqualCanonicalizing([$vpn->id, $metrics->id]);
});

it('skips a colocated vpn node and stays idempotent', function (): void {
    $gateway = grant_gateway_access_node('gateway', '10.44.0.1', RoleName::Gateway);
    $gateway->roles()->create([
        'role' => RoleName::Vpn,
        'status' => LifecycleStatus::Active,
    ]);
    $metrics = grant_gateway_access_node('beast', '10.44.0.9', RoleName::Metrics);

    $action = app(GrantGatewayRoleAccessAction::class);
    $action->execute($gateway);
    $action->execute($gateway);

    expect(NodeAccess::query()->where('consumer_node_id', $gateway->id)->pluck('serving_node_id')->all())
        ->toBe([$metrics->id]);
});

function grant_gateway_access_node(string $name, string $address, RoleName $role): Node
{
    $node = Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => $name.'.example.test',
        'user' => 'orbit',
        'wireguard_ip' => $address,
    ]);
    $node->roles()->create([
        'role' => $role,
        'status' => LifecycleStatus::Active,
    ]);

    return $node;
}
