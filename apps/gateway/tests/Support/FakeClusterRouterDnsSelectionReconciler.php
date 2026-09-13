<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\AppDev\ClusterRouterDnsSelectionReconciler;
use App\Domain\Clusters\ClusterState;
use App\Domain\Shared\LifecycleStatus;
use Closure;
use Throwable;

final class FakeClusterRouterDnsSelectionReconciler implements ClusterRouterDnsSelectionReconciler
{
    /** @var list<array{phase: string, nodeOverrides: array<int, mixed>, clusterOverrides: array<int, mixed>, clusterIds: list<int>}> */
    public array $events = [];

    public ?Throwable $expandFailure = null;

    public ?Throwable $pruneFailure = null;

    public ?Closure $onExpand = null;

    public ?Closure $onPrune = null;

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
        $this->events[] = [
            'phase' => 'expand',
            'nodeOverrides' => $nodeOverrides,
            'clusterOverrides' => $clusterOverrides,
            'clusterIds' => $clusterIds,
        ];

        if ($this->onExpand instanceof Closure) {
            ($this->onExpand)($nodeOverrides, $clusterOverrides, $clusterIds);
        }

        if ($this->expandFailure instanceof Throwable) {
            throw $this->expandFailure;
        }
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
        $this->events[] = [
            'phase' => 'prune',
            'nodeOverrides' => $nodeOverrides,
            'clusterOverrides' => $clusterOverrides,
            'clusterIds' => $clusterIds,
        ];

        if ($this->onPrune instanceof Closure) {
            ($this->onPrune)($nodeOverrides, $clusterOverrides, $clusterIds);
        }

        if ($this->pruneFailure instanceof Throwable) {
            throw $this->pruneFailure;
        }
    }
}
