<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\ClusterRouterDnsSelectionReconciler;
use App\Domain\Clusters\ClusterState;
use App\Domain\Shared\LifecycleStatus;

final readonly class NativeClusterRouterDnsSelectionReconciler implements ClusterRouterDnsSelectionReconciler
{
    public function __construct(
        private DnsmasqPrivateDnsManager $dns,
    ) {}

    /**
     * @param  array<int, array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}>  $nodeOverrides
     * @param  array<int, array{state?: ClusterState, tld?: ?string, router_node_id?: ?int}>  $clusterOverrides
     * @param  list<int>  $clusterIds
     */
    public function expand(
        array $nodeOverrides = [],
        array $clusterOverrides = [],
        array $clusterIds = [],
    ): void {
        $this->dns->convergeSelection($nodeOverrides, $clusterOverrides);
    }

    /**
     * @param  array<int, array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}>  $nodeOverrides
     * @param  array<int, array{state?: ClusterState, tld?: ?string, router_node_id?: ?int}>  $clusterOverrides
     * @param  list<int>  $clusterIds
     */
    public function prune(
        array $nodeOverrides = [],
        array $clusterOverrides = [],
        array $clusterIds = [],
    ): void {
        $this->dns->convergeSelection($nodeOverrides, $clusterOverrides);
    }
}
