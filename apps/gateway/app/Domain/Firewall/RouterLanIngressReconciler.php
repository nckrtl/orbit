<?php

declare(strict_types=1);

namespace App\Domain\Firewall;

use App\Domain\Clusters\ClusterState;
use App\Domain\Shared\LifecycleStatus;

interface RouterLanIngressReconciler
{
    /**
     * @param  array<int, array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}>  $nodeOverrides
     * @param  array<int, array{state?: ClusterState}>  $clusterOverrides
     * @param  list<int>  $clusterIds
     */
    public function expand(
        array $nodeOverrides = [],
        array $clusterOverrides = [],
        array $clusterIds = [],
    ): void;

    /**
     * @param  array<int, array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}>  $nodeOverrides
     * @param  array<int, array{state?: ClusterState}>  $clusterOverrides
     * @param  list<int>  $clusterIds
     */
    public function prune(
        array $nodeOverrides = [],
        array $clusterOverrides = [],
        array $clusterIds = [],
    ): void;
}
