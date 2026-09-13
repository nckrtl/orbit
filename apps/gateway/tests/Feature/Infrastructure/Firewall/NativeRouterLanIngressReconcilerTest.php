<?php

declare(strict_types=1);

use App\Domain\Firewall\RouterLanIngressPolicy;
use App\Domain\Firewall\RouterLanIngressPublisher;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Infrastructure\Firewall\NativeRouterLanIngressReconciler;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Firewall\UfwManagedRule;
use App\Models\Node;

it('publishes desired Router LAN rules only for contactable Cluster Routers', function (): void {
    [$router, $eligible] = router_lan_topology();
    $publisher = new class implements RouterLanIngressPublisher
    {
        /** @var list<array{phase: string, router: int, comments: list<string>}> */
        public array $events = [];

        public function expandRouterLanIngress(Node $router, array $desired, string $managedUser): void
        {
            $this->record('expand', $router, $desired, $managedUser);
        }

        public function pruneRouterLanIngress(Node $router, array $desired, string $managedUser): void
        {
            $this->record('prune', $router, $desired, $managedUser);
        }

        /** @param list<UfwManagedRule> $desired */
        private function record(string $phase, Node $router, array $desired, string $managedUser): void
        {
            expect($managedUser)->toBe('orbit');

            $this->events[] = [
                'phase' => $phase,
                'router' => $router->id,
                'comments' => array_map(
                    static fn (UfwManagedRule $rule): string => $rule->shape->comment,
                    $desired,
                ),
            ];
        }
    };
    $accounts = new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount(user: 'orbit', group: 'orbit', home: '/home/orbit');
        }
    };
    $reconciler = new NativeRouterLanIngressReconciler(
        new RouterLanIngressPolicy,
        new NodeFirewallRuleCatalog,
        $publisher,
        $accounts,
    );

    $clusterId = $router->cluster_id;
    assert(is_int($clusterId));

    $reconciler->expand(clusterIds: [$clusterId]);
    $reconciler->prune(clusterIds: [$clusterId]);

    expect($publisher->events)->toBe([
        [
            'phase' => 'expand',
            'router' => $router->id,
            'comments' => ['orbit:router-lan-https:'.$eligible->id],
        ],
        [
            'phase' => 'prune',
            'router' => $router->id,
            'comments' => ['orbit:router-lan-https:'.$eligible->id],
        ],
    ]);
});
