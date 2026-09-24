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
 * The active `router` row stays the Cluster's Router for every other site. Cleanup marks the old
 * row only after private DNS answers with the new Router and cached answers can have expired.
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
     * The candidate Router Node that private DNS answers with for each Cluster, keyed by Cluster.
     * A candidate that completed `dns-publication` keeps the answer until `database` makes it the
     * active Router, so an unrelated DNS publication never moves the answer back.
     *
     * @return array<int, int>
     */
    public function dnsRouters(): array
    {
        $routers = [];

        NodeRole::query()
            ->where('role', RoleName::Router)
            ->whereNotNull('cluster_id')
            ->whereIn('status', [LifecycleStatus::Provisioning, LifecycleStatus::Failed])
            ->orderBy('id')
            ->get()
            ->filter(static fn (NodeRole $row): bool => self::candidateAnswersDns($row))
            ->each(static function (NodeRole $row) use (&$routers): void {
                $routers[(int) $row->cluster_id] = $row->node_id;
            });

        return $routers;
    }

    /**
     * A candidate serves the Router sites once its Router certificates and workload checks have
     * completed, so the `router-caddy` build includes them. A restore first marks it
     * `rollback:<step>` while it is still `provisioning`: it keeps serving while private DNS moves
     * back and cached answers expire. The restore then marks it `failed`, which withdraws it. A
     * failure before publication has restored or will restore it.
     */
    public static function candidateServes(NodeRole $candidate): bool
    {
        if (! in_array($candidate->status, [LifecycleStatus::Provisioning, LifecycleStatus::Failed], true)) {
            return false;
        }

        $step = ClusterRouterReplacementStep::fromFailedStep($candidate->failed_step);

        if (! $step instanceof ClusterRouterReplacementStep) {
            return false;
        }

        if (self::restoring($candidate)) {
            return $candidate->status === LifecycleStatus::Provisioning;
        }

        if ($candidate->error_code === null) {
            return $step->rank() >= ClusterRouterReplacementStep::WorkloadVerified->rank();
        }

        return $step->rank() > ClusterRouterReplacementStep::DnsPublished->rank();
    }

    /** A candidate that completed `dns-publication` and is not being restored. */
    public static function candidateAnswersDns(NodeRole $candidate): bool
    {
        if (
            ! in_array($candidate->status, [LifecycleStatus::Provisioning, LifecycleStatus::Failed], true)
            || self::restoring($candidate)
        ) {
            return false;
        }

        $step = ClusterRouterReplacementStep::fromFailedStep($candidate->failed_step);

        if (! $step instanceof ClusterRouterReplacementStep) {
            return false;
        }

        return $candidate->error_code === null
            ? $step->rank() >= ClusterRouterReplacementStep::DnsPublished->rank()
            : $step->rank() > ClusterRouterReplacementStep::DnsPublished->rank();
    }

    private static function restoring(NodeRole $candidate): bool
    {
        return str_starts_with((string) $candidate->failed_step, 'rollback:');
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
