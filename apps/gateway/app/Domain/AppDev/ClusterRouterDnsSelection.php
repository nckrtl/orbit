<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\ClusterRouterTransition;
use App\Domain\Routes\RouteCertificateStaging;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Route;

final readonly class ClusterRouterDnsSelection
{
    /**
     * @param  array<int, array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}>  $nodeOverrides
     * @param  array<int, array{state?: ClusterState, tld?: ?string, router_node_id?: ?int}>  $clusterOverrides
     * @return list<string>
     */
    public function clusterTldRecords(array $nodeOverrides = [], array $clusterOverrides = []): array
    {
        $records = [];

        foreach ($this->projections($nodeOverrides, $clusterOverrides) as $projection) {
            $tld = $projection['tld'];
            $routerWireguard = $projection['router_wireguard'];

            if ($tld === null || $routerWireguard === null) {
                continue;
            }

            $records[] = "address=/.{$tld}/{$routerWireguard}";
            $records[] = "local=/{$tld}/";
        }

        return $records;
    }

    /**
     * @param  array<int, array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}>  $nodeOverrides
     * @param  array<int, array{state?: ClusterState, tld?: ?string, router_node_id?: ?int}>  $clusterOverrides
     * @return list<string>
     */
    public function clusterTlds(array $nodeOverrides = [], array $clusterOverrides = []): array
    {
        $tlds = [];

        foreach ($this->projections($nodeOverrides, $clusterOverrides) as $projection) {
            if ($projection['tld'] !== null) {
                $tlds[] = $projection['tld'];
            }
        }

        return array_values(array_unique($tlds));
    }

    /**
     * @param  array<int, array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}>  $nodeOverrides
     * @param  array<int, array{state?: ClusterState, tld?: ?string, router_node_id?: ?int}>  $clusterOverrides
     * @return array{suffixes: array<string, string>, overrides: array<string, array<string, string>>}
     */
    public function answers(
        array $nodeOverrides = [],
        array $clusterOverrides = [],
    ): array {
        $suffixes = [];
        $overrides = [];

        foreach ($this->projections($nodeOverrides, $clusterOverrides) as $projection) {
            $tld = $projection['tld'];
            $routerWireguard = $projection['router_wireguard'];
            $routerLan = $projection['router_lan'];

            if ($tld !== null && $routerWireguard !== null) {
                $suffixes[$tld] = $routerWireguard;
            }

            if ($routerLan === null || $routerWireguard === null) {
                continue;
            }

            $names = $this->selectedNames($projection['cluster_id'], $tld);

            foreach ($projection['eligible'] as $requester) {
                $key = DnsRequester::registered($requester->id, (string) $requester->wireguard_ip)->cacheKey();
                $requesterOverrides = $overrides[$key] ?? [];

                foreach ($names as $name) {
                    $requesterOverrides[$name] = $routerLan;
                }

                $overrides[$key] = $requesterOverrides;
            }
        }

        return [
            'suffixes' => $suffixes,
            'overrides' => $overrides,
        ];
    }

    /**
     * @param  array<int, array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}>  $nodeOverrides
     * @param  array<int, array{state?: ClusterState, tld?: ?string, router_node_id?: ?int}>  $clusterOverrides
     * @return list<array{cluster_id: int, tld: ?string, router_wireguard: ?string, router_lan: ?string, eligible: list<Node>}>
     */
    private function projections(array $nodeOverrides, array $clusterOverrides): array
    {
        $projections = [];

        foreach ($this->clusters($clusterOverrides) as $cluster) {
            $state = $clusterOverrides[$cluster->id]['state'] ?? $cluster->state;

            if ($state !== ClusterState::Active) {
                continue;
            }

            $router = $this->router($cluster, $nodeOverrides, $clusterOverrides);

            if (! $router instanceof Node) {
                continue;
            }

            $routerWireguard = $this->ipv4($router->wireguard_ip);
            $routerLan = $this->ipv4($router->lan_ip);
            $tld = array_key_exists('tld', $clusterOverrides[$cluster->id] ?? [])
                ? $this->tld($clusterOverrides[$cluster->id]['tld'] ?? null)
                : $this->tld($cluster->tld);

            $projections[] = [
                'cluster_id' => $cluster->id,
                'tld' => $tld,
                'router_wireguard' => $routerWireguard,
                'router_lan' => $routerLan,
                'eligible' => $routerLan === null || $routerWireguard === null
                    ? []
                    : $this->eligibleRequesters($cluster->id, $router, $nodeOverrides),
            ];
        }

        return $projections;
    }

    /**
     * @param  array<int, array{state?: ClusterState, tld?: ?string, router_node_id?: ?int}>  $clusterOverrides
     * @return list<Cluster>
     */
    private function clusters(array $clusterOverrides): array
    {
        $ids = Cluster::query()->orderBy('id')->pluck('id')->all();

        foreach (array_keys($clusterOverrides) as $clusterId) {
            $ids[] = (int) $clusterId;
        }

        $ids = array_values(array_unique(array_filter($ids, is_int(...))));

        if ($ids === []) {
            return [];
        }

        return Cluster::query()->whereKey($ids)->orderBy('id')->get()->all();
    }

    /**
     * @param  array<int, array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}>  $nodeOverrides
     * @param  array<int, array{state?: ClusterState, tld?: ?string, router_node_id?: ?int}>  $clusterOverrides
     */
    private function router(Cluster $cluster, array $nodeOverrides, array $clusterOverrides): ?Node
    {
        $override = $clusterOverrides[$cluster->id] ?? [];

        if (array_key_exists('router_node_id', $override)) {
            if (! is_int($override['router_node_id'])) {
                return null;
            }

            $router = Node::query()->find($override['router_node_id']);

            return $router instanceof Node
                ? $this->applyNodeOverrides($router, $nodeOverrides[$router->id] ?? [])
                : null;
        }

        // A Router replacement that published DNS keeps answering with its candidate.
        $candidateId = new ClusterRouterTransition()->dnsRouters()[$cluster->id] ?? null;
        $candidate = is_int($candidateId) ? Node::query()->find($candidateId) : null;

        if ($candidate instanceof Node) {
            return $this->applyNodeOverrides($candidate, $nodeOverrides[$candidate->id] ?? []);
        }

        $assignment = NodeRole::query()
            ->where('cluster_id', $cluster->id)
            ->where('role', RoleName::Router)
            ->where('status', LifecycleStatus::Active)
            ->with('node')
            ->first();

        if (! $assignment instanceof NodeRole) {
            return null;
        }

        return $this->applyNodeOverrides($assignment->node, $nodeOverrides[$assignment->node_id] ?? []);
    }

    /**
     * @param  array<int, array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}>  $nodeOverrides
     * @return list<Node>
     */
    private function eligibleRequesters(int $clusterId, Node $router, array $nodeOverrides): array
    {
        $memberIds = Node::query()
            ->where('cluster_id', $clusterId)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        foreach ($nodeOverrides as $id => $override) {
            if (! array_key_exists('cluster_id', $override)) {
                continue;
            }

            if ($override['cluster_id'] === $clusterId) {
                $memberIds[] = (int) $id;

                continue;
            }

            $memberIds = array_values(array_filter(
                $memberIds,
                static fn (int $memberId): bool => $memberId !== (int) $id,
            ));
        }

        $memberIds = array_values(array_unique($memberIds));
        sort($memberIds);

        if ($memberIds === []) {
            return [];
        }

        $members = Node::query()->whereKey($memberIds)->orderBy('id')->get()->keyBy('id');
        $eligible = [];

        foreach ($memberIds as $id) {
            $member = $members->get($id);

            if (! $member instanceof Node) {
                continue;
            }

            $member = $this->applyNodeOverrides($member, $nodeOverrides[$id] ?? []);

            if ($this->isEligibleRequester($member, $clusterId)) {
                $eligible[] = $member;
            }
        }

        return $eligible;
    }

    private function isEligibleRequester(Node $member, int $clusterId): bool
    {
        return $member->exists
            && $member->cluster_id === $clusterId
            && $member->status === LifecycleStatus::Active
            && $this->ipv4($member->lan_ip) !== null
            && $this->ipv4($member->wireguard_ip) !== null
            && is_string($member->wireguard_public_key)
            && $member->wireguard_public_key !== '';
    }

    /**
     * @return list<string>
     */
    private function selectedNames(int $clusterId, ?string $tld): array
    {
        $names = [];

        if ($tld !== null) {
            $names[] = $tld;
        }

        $routes = Route::query()
            ->where('cluster_id', $clusterId)
            ->whereIn('status', [
                RouteStatus::Active->value,
                RouteStatus::Activating->value,
                RouteStatus::Retiring->value,
                RouteStatus::Pending->value,
                RouteStatus::Failed->value,
            ])
            ->where(static function ($query): void {
                $query
                    ->whereHas('targets')
                    ->orWhereNotIn('status', [
                        RouteStatus::Retiring->value,
                        RouteStatus::Failed->value,
                    ]);
            })
            ->orderBy('id')
            ->get();

        foreach ($routes as $route) {
            $names[] = $this->normalizeName($route->domain);
        }

        // A placement change into this Cluster names its domain once the candidate Router serves it,
        // until a failure starts its restore or cutover makes the Cluster the Route's own.
        $candidates = Route::query()
            ->where('transition_cluster_id', $clusterId)
            ->whereNull('failed_step')
            ->orderBy('id')
            ->get()
            ->filter(static fn (Route $route): bool => RouteCertificateStaging::placementCandidate($route)
                && RouteCertificateStaging::reached($route, RouteReplacementStep::RouterCaddy));

        foreach ($candidates as $route) {
            $names[] = $this->normalizeName($route->domain);
        }

        return array_values(array_unique(array_filter($names, static fn (string $name): bool => $name !== '')));
    }

    /**
     * @param  array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}  $override
     */
    private function applyNodeOverrides(Node $node, array $override): Node
    {
        if ($override === []) {
            return $node;
        }

        $clone = clone $node;

        if (array_key_exists('cluster_id', $override)) {
            $clone->cluster_id = $override['cluster_id'];
            $clone->unsetRelation('cluster');
        }

        if (array_key_exists('lan_ip', $override)) {
            $clone->lan_ip = $override['lan_ip'];
        }

        if (array_key_exists('status', $override)) {
            $clone->status = $override['status'];
        }

        if (array_key_exists('wireguard_ip', $override)) {
            $clone->wireguard_ip = $override['wireguard_ip'];
        }

        if (array_key_exists('wireguard_public_key', $override)) {
            $clone->wireguard_public_key = $override['wireguard_public_key'];
        }

        return $clone;
    }

    private function ipv4(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            ? null
            : $value;
    }

    private function tld(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $this->normalizeName($value);
    }

    private function normalizeName(string $name): string
    {
        return rtrim(strtolower($name), '.');
    }
}
