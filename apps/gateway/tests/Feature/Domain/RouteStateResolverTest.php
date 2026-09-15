<?php

declare(strict_types=1);

use App\Domain\Clusters\ClusterState;
use App\Domain\Routes\RouteStateResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Cluster;
use App\Models\Node;

it('uses the active Cluster TLD when the Node also has a TLD', function (): void {
    $cluster = resolver_cluster('shared', 'cluster.test', ClusterState::Active);
    $node = resolver_node('member', 'node.test', $cluster->id);

    $placement = app(RouteStateResolver::class)->forNode($node);

    expect($placement->clusterId)
        ->toBe($cluster->id)
        ->and($placement->nodeId)
        ->toBeNull()
        ->and($placement->effectiveTld)
        ->toBe('cluster.test');
});

it('uses the Node TLD when the Cluster is inactive', function (): void {
    $cluster = resolver_cluster('inactive', 'cluster.test', ClusterState::Inactive);
    $node = resolver_node('inactive-member', 'node.test', $cluster->id);

    $placement = app(RouteStateResolver::class)->forNode($node);

    expect($placement->nodeId)
        ->toBe($node->id)
        ->and($placement->clusterId)
        ->toBeNull()
        ->and($placement->effectiveTld)
        ->toBe('node.test');
});

it('uses the Node TLD when the active Cluster has no TLD', function (): void {
    $cluster = resolver_cluster('tldless', null, ClusterState::Active);
    $node = resolver_node('tldless-member', 'node.test', $cluster->id);

    $placement = app(RouteStateResolver::class)->forNode($node);

    expect($placement->clusterId)
        ->toBe($cluster->id)
        ->and($placement->nodeId)
        ->toBeNull()
        ->and($placement->effectiveTld)
        ->toBe('node.test');
});

it('uses the Node TLD for a standalone Node', function (): void {
    $node = resolver_node('standalone', 'node.test');

    $placement = app(RouteStateResolver::class)->forNode($node);

    expect($placement->nodeId)
        ->toBe($node->id)
        ->and($placement->clusterId)
        ->toBeNull()
        ->and($placement->effectiveTld)
        ->toBe('node.test');
});

it('leaves the effective TLD empty when neither authority supplies one', function (): void {
    $cluster = resolver_cluster('empty', null, ClusterState::Active);
    $node = resolver_node('empty-member', null, $cluster->id);

    $placement = app(RouteStateResolver::class)->forNode($node);

    expect($placement->effectiveTld)->toBeNull();
});

it('refuses generated domains when neither authority supplies a TLD', function (): void {
    expect(fn () => app(RouteStateResolver::class)->generatedDomain('acme', 'feature', null))
        ->toThrow(ResourceOperationException::class, 'requires a Node TLD or active Cluster TLD');
});

it('applies proposed Cluster TLD and state overrides to the effective TLD', function (): void {
    $cluster = resolver_cluster('override', 'cluster.test', ClusterState::Active);
    $node = resolver_node('override-member', 'node.test', $cluster->id);
    $resolver = app(RouteStateResolver::class);

    expect($resolver->forNode($node, clusterOverrides: [$cluster->id => ['tld' => 'next-cluster.test']])->effectiveTld)
        ->toBe('next-cluster.test')
        ->and($resolver->forNode($node, clusterOverrides: [$cluster->id => ['state' => ClusterState::Inactive]])->effectiveTld)
        ->toBe('node.test')
        ->and($resolver->forNode($node, clusterOverrides: [$cluster->id => ['tld' => null]])->effectiveTld)
        ->toBe('node.test')
        ->and($resolver->forNode(
            $node,
            nodeOverrides: [$node->id => ['tld' => null]],
            clusterOverrides: [$cluster->id => ['tld' => null]],
        )->effectiveTld)
        ->toBeNull();
});

function resolver_cluster(string $name, ?string $tld, ClusterState $state): Cluster
{
    return Cluster::query()->create([
        'name' => $name,
        'tld' => $tld,
        'state' => $state,
    ]);
}

function resolver_node(string $name, ?string $tld, ?int $clusterId = null): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'tld' => $tld,
        'cluster_id' => $clusterId,
        'public_ssh_host' => "{$name}.example.test",
        'wireguard_ip' => '10.44.0.'.(Node::query()->count() + 20),
        'user' => 'orbit',
    ]);
}
