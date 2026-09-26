<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\Analytics\AnalyticsHostname;
use App\Domain\AppDev\ClusterRouterDnsSelection;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\ProxyCli\ProxyCliHostname;
use App\Domain\ProxyCli\ProxyCliState;
use App\Domain\Routes\ClusterRouterTransition;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\WebSocket\WebSocketDnsTarget;
use App\Models\Node;
use Illuminate\Database\Eloquent\Builder;

final readonly class AppDevDnsConfigRenderer
{
    public function __construct(
        private AppDevSiteRepository $sites,
        private ClusterRouterDnsSelection $selection = new ClusterRouterDnsSelection,
        private ClusterRouterTransition $routerTransitions = new ClusterRouterTransition,
        private WebSocketDnsTarget $websocket = new WebSocketDnsTarget,
    ) {}

    /**
     * @param  array<int, array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}>  $nodeOverrides
     * @param  array<int, array{state?: ClusterState, tld?: ?string, router_node_id?: ?int}>  $clusterOverrides
     */
    public function render(
        ?Node $pendingNode = null,
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
        // A Router selection names the Router Node that answers: a selection being published or
        // restored, else a stored Router replacement that has published DNS.
        $selectedRouters = array_values(array_replace(
            $this->routerTransitions->dnsRouters(),
            $this->routerOverrides($clusterOverrides),
        ));
        $records = $nodes
            ->toBase()
            ->merge($this->sites
                ->all()
                ->groupBy('domain')
                ->map(fn ($sites): string => $this->hostRecord(
                    $sites->values()->all(),
                    $selectedRouters,
                )));
        // `node:role:add gateway gateway --converge` marks the singleton assignment provisioning while
        // it republishes DNS, so a converging holder keeps gateway.orbit; an active holder wins.
        $gateway = null;
        foreach ([LifecycleStatus::Active, LifecycleStatus::Provisioning] as $gatewayStatus) {
            $gateway ??= Node::query()
                ->where('status', LifecycleStatus::Active->value)
                ->whereNotNull('wireguard_ip')
                ->whereHas('roles', static fn (Builder $q): Builder => $q->where('role', RoleName::Gateway->value)->where(
                    'status',
                    $gatewayStatus->value,
                ))
                ->first();
        }
        if ($gateway instanceof Node) {
            $records->push("host-record=gateway.orbit,{$gateway->wireguard_ip}");

            $metrics = $this->roleNode(RoleName::Metrics, $pendingNode);
            if ($metrics instanceof Node) {
                $records->push("host-record=metrics.orbit,{$gateway->wireguard_ip}");
            }
        }

        $websocket = $this->websocket->node($this->roleNode(RoleName::WebSocket, $pendingNode));

        if ($websocket instanceof Node) {
            $records->push("host-record=reverb.orbit,{$websocket->wireguard_ip}");
        }

        $analytics = $this->roleNode(RoleName::Analytics, $pendingNode);

        if ($analytics instanceof Node) {
            $records->push('host-record='.AnalyticsHostname::Value.",{$analytics->wireguard_ip}");
        }

        $proxycli = $this->proxycliNode();

        if ($proxycli instanceof Node) {
            $records->push('host-record='.ProxyCliHostname::Value.",{$proxycli->wireguard_ip}");
        }

        foreach ($this->selection->clusterTldRecords($nodeOverrides, $clusterOverrides) as $record) {
            $records->push($record);
        }

        return '# Managed by Orbit.'.PHP_EOL.$records->unique()->sort()->implode(PHP_EOL).PHP_EOL;
    }

    private function proxycliNode(): ?Node
    {
        $state = app(ProxyCliState::class);

        if (! $state->enabled()) {
            return null;
        }

        $nodeId = $state->nodeId();

        if ($nodeId === null) {
            return null;
        }

        $node = Node::query()->find($nodeId);

        return $node instanceof Node && is_string($node->wireguard_ip) && $node->wireguard_ip !== ''
            ? $node
            : null;
    }

    /**
     * The Node that holds a singleton role. `node:role:add NODE ROLE --converge` marks the assignment
     * provisioning while it republishes DNS, so a converging holder keeps its record; an active holder
     * wins. A pending Node counts while it is still being added.
     */
    private function roleNode(RoleName $role, ?Node $pendingNode): ?Node
    {
        $node = null;

        foreach ([LifecycleStatus::Active, LifecycleStatus::Provisioning] as $status) {
            $node ??= Node::query()
                ->where(static function (Builder $q) use ($pendingNode): void {
                    $q->where('status', LifecycleStatus::Active->value);

                    if ($pendingNode instanceof Node && $pendingNode->exists) {
                        $q->orWhere('id', $pendingNode->id);
                    }
                })
                ->whereNotNull('wireguard_ip')
                ->whereHas('roles', static fn (Builder $q): Builder => $q
                    ->where('role', $role->value)
                    ->where('status', $status->value))
                ->first();
        }

        return $node;
    }

    /**
     * @param  array<int, array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}>  $nodeOverrides
     * @param  array<int, array{state?: ClusterState, tld?: ?string, router_node_id?: ?int}>  $clusterOverrides
     */
    public function catalog(
        ?Node $pendingNode = null,
        array $nodeOverrides = [],
        array $clusterOverrides = [],
    ): PrivateDnsAnswerCatalog {
        $catalog = PrivateDnsAnswerCatalog::fromDnsmasqConfiguration(
            $this->render($pendingNode, $nodeOverrides, $clusterOverrides),
        );
        $answers = $this->selection->answers($nodeOverrides, $clusterOverrides);
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
     * A domain answers with its Router, else its workload. A Router selection that is being
     * published or restored names the Router Node that answers; otherwise a site that serves a
     * second placement or a second Router never answers while the current site exists.
     *
     * @param  list<AppDevSite>  $sites
     * @param  list<int>  $selectedRouters
     */
    private function hostRecord(array $sites, array $selectedRouters): string
    {
        $sites = collect($sites);
        $selected = $sites->filter(static fn (AppDevSite $site): bool => in_array($site->nodeId, $selectedRouters, true));
        $current = $sites->reject(static fn (AppDevSite $site): bool => $site->secondary);
        $site = $selected->first(static fn (AppDevSite $candidate): bool => $candidate->isProxy())
            ?? $selected->first()
            ?? $current->first(static fn (AppDevSite $candidate): bool => $candidate->isProxy())
            ?? $current->first()
            ?? $sites->first();

        /** @var AppDevSite $site */
        return "host-record={$site->domain},{$site->nodeAddress}";
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
