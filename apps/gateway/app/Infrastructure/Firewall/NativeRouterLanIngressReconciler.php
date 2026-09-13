<?php

declare(strict_types=1);

namespace App\Infrastructure\Firewall;

use App\Domain\Clusters\ClusterState;
use App\Domain\Firewall\RouterLanIngressPolicy;
use App\Domain\Firewall\RouterLanIngressPublisher;
use App\Domain\Firewall\RouterLanIngressReconciler;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Shared\LifecycleStatus;

final readonly class NativeRouterLanIngressReconciler implements RouterLanIngressReconciler
{
    public function __construct(
        private RouterLanIngressPolicy $policy,
        private NodeFirewallRuleCatalog $catalog,
        private RouterLanIngressPublisher $publisher,
        private ManagedUserAccountResolver $accounts,
    ) {}

    /**
     * @param  array<int, array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}>  $nodeOverrides
     * @param  array<int, array{state?: ClusterState}>  $clusterOverrides
     * @param  list<int>  $clusterIds
     */
    public function expand(
        array $nodeOverrides = [],
        array $clusterOverrides = [],
        array $clusterIds = [],
    ): void {
        $this->publish($nodeOverrides, $clusterOverrides, $clusterIds, expand: true, prune: false);
    }

    /**
     * @param  array<int, array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}>  $nodeOverrides
     * @param  array<int, array{state?: ClusterState}>  $clusterOverrides
     * @param  list<int>  $clusterIds
     */
    public function prune(
        array $nodeOverrides = [],
        array $clusterOverrides = [],
        array $clusterIds = [],
    ): void {
        $this->publish($nodeOverrides, $clusterOverrides, $clusterIds, expand: false, prune: true);
    }

    /**
     * @param  array<int, array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}>  $nodeOverrides
     * @param  array<int, array{state?: ClusterState}>  $clusterOverrides
     * @param  list<int>  $clusterIds
     */
    private function publish(
        array $nodeOverrides,
        array $clusterOverrides,
        array $clusterIds,
        bool $expand,
        bool $prune,
    ): void {
        foreach ($this->policy->affectedRouters($nodeOverrides, $clusterOverrides, $clusterIds) as $router) {
            if (! $this->policy->routerHasContactableLan($router, $nodeOverrides)) {
                continue;
            }

            $desired = $this->catalog->routerLanIngress($router, $nodeOverrides, $clusterOverrides);
            $user = $this->accounts->resolve($router)->user;

            if ($expand) {
                $this->publisher->expandRouterLanIngress($router, $desired, $user);
            }

            if ($prune) {
                $this->publisher->pruneRouterLanIngress($router, $desired, $user);
            }
        }
    }
}
