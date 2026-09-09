<?php

declare(strict_types=1);

namespace App\Actions\Clusters;

use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteReconciliationGuard;
use App\Domain\Routes\RouteRemovalGuard;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Cluster;
use App\Models\NodeRole;
use Throwable;

final readonly class ClearClusterRouterAction
{
    public function __construct(
        private RoleBaselineConverger $baselines,
        private ?RouteReconciliationGuard $routes = null,
        private ?RouteRemovalGuard $removal = null,
    ) {}

    public function execute(Cluster $cluster): Cluster
    {
        ($this->routes ?? app(RouteReconciliationGuard::class))->assertClusterRouterMutable($cluster->id);
        ($this->removal ?? app(RouteRemovalGuard::class))->assertRouterRemovable($cluster);
        $cluster->refresh();

        if ($cluster->state === ClusterState::Active && $cluster->tld !== null) {
            throw new ResourceOperationException(
                errorCode: 'cluster.active_router_required',
                message: 'Deactivate the TLD-bearing Cluster or remove its TLD before clearing its Router.',
                status: 409,
            );
        }

        $assignments = NodeRole::query()
            ->where('cluster_id', $cluster->id)
            ->where('role', RoleName::Router)
            ->get()
            ->sortBy(static fn (NodeRole $assignment): int => $assignment->status === LifecycleStatus::Active ? 1 : 0)
            ->values();

        if ($assignments->isEmpty()) {
            return $cluster;
        }

        foreach ($assignments as $assignment) {
            $assignment->update(['status' => LifecycleStatus::Removing, 'failed_step' => null, 'error_code' => null]);

            try {
                $this->baselines->remove($assignment->node, $assignment, false);
                $assignment->delete();
            } catch (Throwable $exception) {
                $step = property_exists($exception, 'step') && is_string($exception->step)
                    ? $exception->step
                    : 'baseline';
                $errorCode = property_exists($exception, 'errorCode') && is_string($exception->errorCode)
                    ? $exception->errorCode
                    : 'node_role.remove_failed';
                $assignment->update([
                    'status' => LifecycleStatus::Failed,
                    'failed_step' => "remove:{$step}",
                    'error_code' => $errorCode,
                ]);

                throw $exception;
            }
        }

        return $cluster->refresh();
    }
}
