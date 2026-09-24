<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Support\Collection;

/**
 * Reads a Router replacement from its stored `router` role rows. From `router-caddy` until
 * `cleanup`, both the old Router and the candidate serve the Cluster's Router sites, because
 * private DNS points at the old Router until `dns-publication` and clients can hold that answer.
 * The active `router` row stays the Cluster's Router for every other site.
 */
final readonly class ClusterRouterTransition
{
    /** The marker a cleanup writes on the old Router row before it builds that Router. */
    public const string OldRouterCleanup = 'cleanup';

    /**
     * The Routers that serve a Cluster's Router sites next to its active Router, keyed by Cluster.
     *
     * @return array<int, list<Node>>
     */
    public function secondRouters(): array
    {
        $rows = NodeRole::query()
            ->with('node')
            ->where('role', RoleName::Router)
            ->whereNotNull('cluster_id')
            ->orderBy('id')
            ->get();
        /** @var Collection<int, NodeRole> $active */
        $active = $rows
            ->filter(static fn (NodeRole $row): bool => $row->status === LifecycleStatus::Active)
            ->keyBy('cluster_id');
        $routers = [];

        foreach ($rows as $row) {
            $clusterId = (int) $row->cluster_id;
            $serves = $row->status === LifecycleStatus::Removing
                ? self::oldRouterServes($row, $active->get($clusterId))
                : self::candidateServes($row);

            if ($serves) {
                $routers[$clusterId][] = $row->node;
            }
        }

        return $routers;
    }

    /**
     * A candidate serves the Router sites once its Router certificates and workload checks have
     * completed, so the `router-caddy` build includes them. A restore marks the candidate before
     * it builds, and a failure before publication has restored or will restore it.
     */
    public static function candidateServes(NodeRole $candidate): bool
    {
        if (! in_array($candidate->status, [LifecycleStatus::Provisioning, LifecycleStatus::Failed], true)) {
            return false;
        }

        $step = ClusterRouterReplacementStep::fromFailedStep($candidate->failed_step);

        if (! $step instanceof ClusterRouterReplacementStep || str_starts_with((string) $candidate->failed_step, 'rollback:')) {
            return false;
        }

        if ($candidate->error_code === null) {
            return $step->rank() >= ClusterRouterReplacementStep::WorkloadVerified->rank();
        }

        return $step->rank() > ClusterRouterReplacementStep::DnsPublished->rank();
    }

    /**
     * After `database`, the old Router keeps serving the Router sites until its cleanup marks its
     * row and builds it.
     */
    public static function oldRouterServes(NodeRole $old, ?NodeRole $active): bool
    {
        if ($old->status !== LifecycleStatus::Removing || $old->failed_step !== null || ! $active instanceof NodeRole) {
            return false;
        }

        $step = ClusterRouterReplacementStep::fromFailedStep($active->failed_step);

        return $step instanceof ClusterRouterReplacementStep
            && $step->rank() >= ClusterRouterReplacementStep::DatabaseCutover->rank();
    }
}
