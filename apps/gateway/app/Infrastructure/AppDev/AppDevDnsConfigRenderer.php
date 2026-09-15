<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\ClusterRouterDnsSelection;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\AppInstance;
use App\Models\HerdrSession;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Database\Eloquent\Builder;

final readonly class AppDevDnsConfigRenderer
{
    public function __construct(
        private AppDevSiteRepository $sites,
        private ClusterRouterDnsSelection $selection = new ClusterRouterDnsSelection,
    ) {}

    /**
     * @param  array<int, array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}>  $nodeOverrides
     * @param  array<int, array{state?: ClusterState, tld?: ?string, router_node_id?: ?int}>  $clusterOverrides
     */
    public function render(
        ?Node $pendingNode = null,
        ?Route $pendingRoute = null,
        ?AppInstance $unavailableInstance = null,
        ?Route $additionalRoute = null,
        array $nodeOverrides = [],
        array $clusterOverrides = [],
    ): string {
        $nodes = Node::query()
            ->where(static function (Builder $q) use ($pendingNode): void {
                $q->where('status', LifecycleStatus::Active->value);
                if (
                    $pendingNode instanceof Node
                    && $pendingNode->exists
                    && $pendingNode->status === LifecycleStatus::Provisioning
                ) {
                    $q->orWhere('id', $pendingNode->id);
                }
            })
            ->whereHas('roles', static fn (Builder $q): Builder => $q->where(
                'role',
                RoleName::AppDev->value,
            )->whereIn('status', [LifecycleStatus::Provisioning->value, LifecycleStatus::Active->value]))
            ->whereNotNull('wireguard_ip')
            ->whereNotNull('tld')
            ->get();
        $clusterTlds = $this->selection->clusterTlds($nodeOverrides, $clusterOverrides);
        $nodes = $nodes
            ->flatMap(static function (Node $n) use ($clusterTlds): array {
                $records = ["local=/{$n->tld}/"];

                if (! in_array(rtrim(strtolower((string) $n->tld), '.'), $clusterTlds, true)) {
                    $records[] = "address=/.{$n->tld}/{$n->wireguard_ip}";
                }

                return $records;
            });
        $records = $nodes
            ->toBase()
            ->merge($this->sites
                ->all(
                    $pendingRoute,
                    $unavailableInstance,
                    $additionalRoute,
                    $this->routerOverrides($clusterOverrides),
                )
                ->groupBy('domain')
                ->map(static function ($sites): string {
                    /** @var AppDevSite $site */
                    $site = $sites->first(
                        static fn (AppDevSite $candidate): bool => $candidate->isProxy(),
                    ) ?? $sites->first();

                    return "host-record={$site->domain},{$site->nodeAddress}";
                }));
        $gateway = Node::query()
            ->where('status', LifecycleStatus::Active->value)
            ->whereNotNull('wireguard_ip')
            ->whereHas('roles', static fn (Builder $q): Builder => $q->where('role', RoleName::Gateway->value)->where(
                'status',
                LifecycleStatus::Active->value,
            ))
            ->first();
        HerdrSession::query()
            ->with('node')
            ->where('observer_status', 'published')
            ->whereNotNull('observer_hostname')
            ->orderBy('id')
            ->get()
            ->each(static function (HerdrSession $session) use ($records): void {
                $address = $session->node->wireguard_ip;

                if (! is_string($address) || $address === '') {
                    return;
                }

                $records->push("host-record={$session->observer_hostname},{$address}");
            });

        if ($gateway instanceof Node) {
            $records->push("host-record=gateway.orbit,{$gateway->wireguard_ip}");

            $metrics = Node::query()
                ->where(static function (Builder $q) use ($pendingNode): void {
                    $q->where('status', LifecycleStatus::Active->value);

                    if ($pendingNode instanceof Node && $pendingNode->exists) {
                        $q->orWhere('id', $pendingNode->id);
                    }
                })
                ->whereNotNull('wireguard_ip')
                ->whereHas('roles', static function (Builder $q) use ($pendingNode): void {
                    $q->where('role', RoleName::Metrics->value)
                        ->where(static function (Builder $q) use ($pendingNode): void {
                            $q->where('status', LifecycleStatus::Active->value);

                            if ($pendingNode instanceof Node && $pendingNode->exists) {
                                $q->orWhere(static fn (Builder $q): Builder => $q
                                    ->where('node_id', $pendingNode->id)
                                    ->where('status', LifecycleStatus::Provisioning->value));
                            }
                        });
                })
                ->first();
            if ($metrics instanceof Node) {
                $records->push("host-record=metrics.orbit,{$gateway->wireguard_ip}");
            }
        }

        foreach ($this->selection->clusterTldRecords($nodeOverrides, $clusterOverrides) as $record) {
            $records->push($record);
        }

        return '# Managed by Orbit.'.PHP_EOL.$records->unique()->sort()->implode(PHP_EOL).PHP_EOL;
    }

    /**
     * @param  array<int, array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}>  $nodeOverrides
     * @param  array<int, array{state?: ClusterState, tld?: ?string, router_node_id?: ?int}>  $clusterOverrides
     */
    public function catalog(
        ?Node $pendingNode = null,
        ?Route $pendingRoute = null,
        ?AppInstance $unavailableInstance = null,
        ?Route $additionalRoute = null,
        array $nodeOverrides = [],
        array $clusterOverrides = [],
    ): PrivateDnsAnswerCatalog {
        $catalog = PrivateDnsAnswerCatalog::fromDnsmasqConfiguration(
            $this->render(
                $pendingNode,
                $pendingRoute,
                $unavailableInstance,
                $additionalRoute,
                $nodeOverrides,
                $clusterOverrides,
            ),
        );
        $answers = $this->selection->answers($nodeOverrides, $clusterOverrides, $additionalRoute);
        $overrides = $catalog->overrides;

        foreach ($answers['overrides'] as $cacheKey => $requesterOverrides) {
            $overrides[$cacheKey] = [
                ...($overrides[$cacheKey] ?? []),
                ...$requesterOverrides,
            ];
        }

        return new PrivateDnsAnswerCatalog(
            exact: $catalog->exact,
            suffixes: [
                ...$catalog->suffixes,
                ...$answers['suffixes'],
            ],
            overrides: $overrides,
        );
    }

    /**
     * @param  array<int, array{state?: ClusterState, tld?: ?string, router_node_id?: ?int}>  $clusterOverrides
     * @return array<int, int>
     */
    private function routerOverrides(array $clusterOverrides): array
    {
        $overrides = [];

        foreach ($clusterOverrides as $clusterId => $override) {
            if (array_key_exists('router_node_id', $override) && is_int($override['router_node_id'])) {
                $overrides[(int) $clusterId] = $override['router_node_id'];
            }
        }

        return $overrides;
    }

    /** @return array<string, int> */
    public function registeredRequesters(): array
    {
        $requesters = [];

        Node::query()
            ->where('status', LifecycleStatus::Active->value)
            ->whereNotNull('wireguard_ip')
            ->orderBy('id')
            ->get()
            ->each(static function (Node $node) use (&$requesters): void {
                $address = DnsAddress::normalize((string) $node->wireguard_ip);
                if ($address === null) {
                    return;
                }

                $requesters[$address] = $node->id;
            });

        return $requesters;
    }
}
