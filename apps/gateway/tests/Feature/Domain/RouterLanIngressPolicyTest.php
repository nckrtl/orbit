<?php

declare(strict_types=1);

use App\Domain\Clusters\ClusterState;
use App\Domain\Firewall\RouterLanIngressPolicy;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Firewall\UfwRuleShape;
use App\Models\Cluster;
use App\Models\Node;

it('admits only active LAN-configured WireGuard members of the same active Cluster', function (): void {
    [$router, $eligible] = router_lan_topology();
    $denied = router_lan_denied_sources($router->cluster);

    $sources = array_map(
        static fn (Node $node): int => $node->id,
        new RouterLanIngressPolicy()->eligibleSources($router),
    );

    expect($sources)
        ->toBe([$eligible->id])
        ->and($sources)
        ->not->toContain($router->id)
        ->and($sources)
        ->not->toContain(...array_map(static fn (Node $node): int => $node->id, $denied));
});

it('renders Router-owned LAN HTTPS rules that stay idempotent across extra Routes', function (): void {
    [$router, $eligible] = router_lan_topology();
    $catalog = new NodeFirewallRuleCatalog;
    $first = $catalog->routerLanIngress($router);
    $second = $catalog->forRole($router, RoleName::Router);

    expect($first)
        ->toHaveCount(1)
        ->and($second)
        ->toEqual($first)
        ->and($first[0]->shape)
        ->toEqual(new UfwRuleShape(
            comment: 'orbit:router-lan-https:'.$eligible->id,
            action: 'allow',
            direction: 'in',
            source: '10.20.0.11',
            destination: '10.20.0.10',
            port: '443',
            protocol: 'tcp',
            inInterface: null,
            outInterface: null,
            family: 'v4',
        ))
        ->and($first[0]->arguments)
        ->toBe([
            'sudo',
            'ufw',
            'allow',
            'in',
            'proto',
            'tcp',
            'from',
            '10.20.0.11',
            'to',
            '10.20.0.10',
            'port',
            '443',
            'comment',
            'orbit:router-lan-https:'.$eligible->id,
        ])
        ->and(array_map(static fn ($rule) => $rule->shape->comment, $catalog->forNode($router)))
        ->toBe(['orbit:public-ssh-recovery', 'orbit:wireguard-members'])
        ->and($catalog->forRole($router, RoleName::AppProd))
        ->toBe([])
        ->and($catalog->metricsGrafanaUpstream($router, '10.44.0.1')->shape->comment)
        ->toBe('orbit:metrics-grafana-upstream');
});

it('drops LAN ingress when the Cluster is inactive or the Router has no LAN', function (): void {
    [$router] = router_lan_topology();
    $router->cluster->update(['state' => ClusterState::Inactive]);

    expect(new NodeFirewallRuleCatalog()->routerLanIngress($router->refresh()->load('cluster')))
        ->toBe([]);

    $router->cluster->update(['state' => ClusterState::Active]);
    $router->update(['lan_ip' => null]);

    expect(new NodeFirewallRuleCatalog()->routerLanIngress($router->refresh()))
        ->toBe([]);
});

/**
 * @return array{Node, Node}
 */
function router_lan_topology(): array
{
    $cluster = Cluster::query()->create([
        'name' => 'lan-cluster',
        'state' => ClusterState::Active,
    ]);
    $router = router_lan_node('lan-router', '10.44.0.10', '10.20.0.10', $cluster);
    $eligible = router_lan_node('lan-member', '10.44.0.11', '10.20.0.11', $cluster);
    $router->roles()->create([
        'role' => RoleName::Router,
        'status' => LifecycleStatus::Active,
        'cluster_id' => $cluster->id,
    ]);

    return [$router->fresh('cluster') ?? $router, $eligible];
}

/** @return list<Node> */
function router_lan_denied_sources(Cluster $cluster): array
{
    $other = Cluster::query()->create([
        'name' => 'other-lan-cluster',
        'state' => ClusterState::Active,
    ]);

    return [
        router_lan_node('vpn-only', '10.44.0.12', null, $cluster),
        router_lan_node('inactive-member', '10.44.0.13', '10.20.0.13', $cluster, LifecycleStatus::Failed),
        router_lan_node('standalone', '10.44.0.14', '10.20.0.14'),
        router_lan_node('other-cluster', '10.44.0.15', '10.20.0.15', $other),
        router_lan_node('lan-only', '10.44.0.16', '10.20.0.16', $cluster, publicKey: null),
        router_lan_node('public-source', '10.44.0.17', '10.20.0.17'),
    ];
}

function router_lan_node(
    string $name,
    string $wireguardIp,
    ?string $lanIp,
    ?Cluster $cluster = null,
    LifecycleStatus $status = LifecycleStatus::Active,
    ?string $publicKey = 'wg-key',
): Node {
    return Node::query()->create([
        'name' => $name,
        'cluster_id' => $cluster?->id,
        'status' => $status,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.'.str_replace('10.44.0.', '', $wireguardIp),
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => $wireguardIp,
        'lan_ip' => $lanIp,
        'wireguard_public_key' => $publicKey,
    ]);
}
