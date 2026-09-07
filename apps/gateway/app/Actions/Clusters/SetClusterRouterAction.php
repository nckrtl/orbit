<?php

declare(strict_types=1);

namespace App\Actions\Clusters;

use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteReconciliationGuard;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class SetClusterRouterAction
{
    public function __construct(
        private RoleBaselineConverger $baselines,
        private ?RouteReconciliationGuard $routes = null,
    ) {}

    public function execute(Cluster $cluster, Node $node): Cluster
    {
        ($this->routes ?? app(RouteReconciliationGuard::class))->assertClusterRouterMutable($cluster->id);
        $node->refresh();

        if ($node->cluster_id !== $cluster->id || $node->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException(
                errorCode: 'cluster.router_node_invalid',
                message: 'A Cluster Router must be an active member Node.',
                status: 409,
            );
        }

        $active = NodeRole::query()
            ->where('cluster_id', $cluster->id)
            ->where('role', RoleName::Router)
            ->where('status', LifecycleStatus::Active)
            ->first();

        if ($active instanceof NodeRole && $active->node_id === $node->id) {
            $this->finishOldCleanup($cluster, $active);

            return $cluster->refresh();
        }

        $candidate = NodeRole::query()->firstOrCreate(
            ['node_id' => $node->id, 'role' => RoleName::Router],
            ['cluster_id' => $cluster->id, 'status' => LifecycleStatus::Provisioning],
        );
        $candidate->update([
            'cluster_id' => $cluster->id,
            'status' => LifecycleStatus::Provisioning,
            'failed_step' => null,
            'error_code' => null,
        ]);

        try {
            $this->baselines->converge($node, $candidate);
        } catch (Throwable $exception) {
            $this->fail($candidate, 'converge', $exception);
        }

        DB::transaction(static function () use ($active, $candidate): void {
            $active?->update(['status' => LifecycleStatus::Removing]);
            $candidate->update(['status' => LifecycleStatus::Active, 'failed_step' => null, 'error_code' => null]);
        });

        $this->finishOldCleanup($cluster, $candidate);

        return $cluster->refresh();
    }

    private function finishOldCleanup(Cluster $cluster, NodeRole $current): void
    {
        $old = NodeRole::query()
            ->where('cluster_id', $cluster->id)
            ->where('role', RoleName::Router)
            ->whereKeyNot($current->id)
            ->first();

        if (! $old instanceof NodeRole) {
            return;
        }

        try {
            $this->baselines->remove($old->node, $old, false);
            $old->delete();
        } catch (Throwable $exception) {
            $this->fail($old, 'remove', $exception);
        }
    }

    private function fail(NodeRole $assignment, string $boundary, Throwable $exception): never
    {
        $step = property_exists($exception, 'step') && is_string($exception->step) ? $exception->step : 'baseline';
        $errorCode = property_exists($exception, 'errorCode') && is_string($exception->errorCode)
            ? $exception->errorCode
            : 'node_role.operation_failed';
        $assignment->update([
            'status' => LifecycleStatus::Failed,
            'failed_step' => "{$boundary}:{$step}",
            'error_code' => $errorCode,
        ]);

        throw $exception;
    }
}
