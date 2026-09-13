<?php

declare(strict_types=1);

namespace App\Domain\Firewall;

use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Support\Collection;

final readonly class RouterLanIngressPolicy
{
    public const string CommentPrefix = 'orbit:router-lan-https';

    /**
     * @param  array<int, array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}>  $nodeOverrides
     * @param  array<int, array{state?: ClusterState}>  $clusterOverrides
     * @return list<Node>
     */
    public function eligibleSources(Node $router, array $nodeOverrides = [], array $clusterOverrides = []): array
    {
        $router = $this->applyNodeOverrides($router, $this->overrideFor($router, $nodeOverrides));

        if (! $this->routerAdmits($router, $clusterOverrides)) {
            return [];
        }

        $clusterId = $router->cluster_id;
        assert(is_int($clusterId));

        $memberIds = Node::query()
            ->where('cluster_id', $clusterId)
            ->whereKeyNot($router->id)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        foreach ($nodeOverrides as $id => $override) {
            if ($id === $router->id || ! array_key_exists('cluster_id', $override)) {
                continue;
            }

            if ($override['cluster_id'] === $clusterId) {
                $memberIds[] = $id;

                continue;
            }

            $memberIds = array_values(array_filter(
                $memberIds,
                static fn (int $memberId): bool => $memberId !== $id,
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

            if ($this->isEligibleSource($member, $clusterId)) {
                $eligible[] = $member;
            }
        }

        return $eligible;
    }

    /**
     * @param  array<int, array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}>  $nodeOverrides
     * @param  array<int, array{state?: ClusterState}>  $clusterOverrides
     * @param  list<int>  $clusterIds
     * @return Collection<int, Node>
     */
    public function affectedRouters(
        array $nodeOverrides = [],
        array $clusterOverrides = [],
        array $clusterIds = [],
    ): Collection {
        $ids = $clusterIds;

        foreach (array_keys($clusterOverrides) as $clusterId) {
            $ids[] = (int) $clusterId;
        }

        foreach ($nodeOverrides as $nodeId => $override) {
            $node = Node::query()->find((int) $nodeId);

            if ($node instanceof Node && is_int($node->cluster_id)) {
                $ids[] = $node->cluster_id;
            }

            if (array_key_exists('cluster_id', $override) && is_int($override['cluster_id'])) {
                $ids[] = $override['cluster_id'];
            }
        }

        $ids = array_values(array_unique(array_filter($ids, is_int(...))));

        if ($ids === []) {
            return new Collection;
        }

        return NodeRole::query()
            ->where('role', RoleName::Router)
            ->where('status', LifecycleStatus::Active)
            ->whereIn('cluster_id', $ids)
            ->with(['node.cluster'])
            ->orderBy('id')
            ->get()
            ->map(static fn (NodeRole $assignment): Node => $assignment->node)
            ->unique('id')
            ->values();
    }

    public function commentFor(int $sourceNodeId): string
    {
        return self::CommentPrefix.':'.$sourceNodeId;
    }

    public function ownsComment(string $comment): bool
    {
        return $comment === self::CommentPrefix
            || str_starts_with($comment, self::CommentPrefix.':');
    }

    /**
     * @param  array<int, array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}>  $nodeOverrides
     */
    public function routerHasContactableLan(Node $router, array $nodeOverrides = []): bool
    {
        $proposed = $this->ipv4($this->applyNodeOverrides($router, $this->overrideFor($router, $nodeOverrides))->lan_ip);

        return $proposed !== null || $this->ipv4($router->lan_ip) !== null;
    }

    /**
     * @param  array<int, array{state?: ClusterState}>  $clusterOverrides
     */
    private function routerAdmits(Node $router, array $clusterOverrides): bool
    {
        if (! $router->exists || $router->id < 1) {
            return false;
        }

        if ($this->ipv4($router->lan_ip) === null || $this->ipv4($router->wireguard_ip) === null) {
            return false;
        }

        if (! is_string($router->wireguard_public_key) || $router->wireguard_public_key === '') {
            return false;
        }

        if (! is_int($router->cluster_id)) {
            return false;
        }

        $cluster = $router->relationLoaded('cluster')
            ? $router->cluster
            : Cluster::query()->find($router->cluster_id);

        if (! $cluster instanceof Cluster) {
            return false;
        }

        $state = $clusterOverrides[$cluster->id]['state'] ?? $cluster->state;

        return $state === ClusterState::Active;
    }

    private function isEligibleSource(Node $member, int $clusterId): bool
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
     * @param  array<int, array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}>  $nodeOverrides
     * @return array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}
     */
    private function overrideFor(Node $node, array $nodeOverrides): array
    {
        return $nodeOverrides[$node->id] ?? [];
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
}
