<?php

declare(strict_types=1);

namespace App\Actions\Clusters;

use App\Domain\AppDev\ClusterRouterDnsSelectionReconciler;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Clusters\ClusterRouterOperationLock;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\ClusterRouterReplacementProjector;
use App\Domain\Routes\ClusterRouterReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Route;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class SetClusterRouterAction
{
    public function __construct(
        private RoleBaselineConverger $baselines,
        private ClusterRouterOperationLock $operations,
        private ?ClusterRouterDnsSelectionReconciler $dnsSelection = null,
        private ?ClusterRouterReplacementProjector $replacements = null,
    ) {}

    public function execute(Cluster $cluster, Node $node): Cluster
    {
        return $this->operations->run(
            $cluster->id,
            fn (): Cluster => $this->executeOwned($cluster->id, $node->id),
        );
    }

    private function executeOwned(int $clusterId, int $nodeId): Cluster
    {
        $cluster = Cluster::query()->findOrFail($clusterId);
        $node = Node::query()->findOrFail($nodeId);

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
            $this->assertNoConflictingCandidate($cluster->id, $node->id);
            $this->finishReplacementCleanup($cluster, $active);
            $this->finishOldCleanup($cluster, $active);
            $this->dnsSelection()->prune(clusterIds: [$clusterId]);

            return $cluster->refresh();
        }

        $this->assertNoConflictingCandidate($cluster->id, $node->id);

        $candidate = NodeRole::query()->firstOrCreate(
            ['node_id' => $node->id, 'role' => RoleName::Router],
            ['cluster_id' => $cluster->id, 'status' => LifecycleStatus::Provisioning],
        );
        $resumeFrom = ClusterRouterReplacementStep::fromFailedStep($candidate->failed_step);
        $resumingReplacement = $resumeFrom instanceof ClusterRouterReplacementStep;
        $candidate->update([
            'cluster_id' => $cluster->id,
            'status' => LifecycleStatus::Provisioning,
            'failed_step' => $resumingReplacement ? $candidate->failed_step : null,
            'error_code' => $resumingReplacement ? $candidate->error_code : null,
        ]);
        $candidate->refresh();

        $routes = $this->clusterRoutes($cluster->id);
        $oldRouter = $active?->node;
        $published = $this->publicationCompleted($candidate);

        try {
            if (! $resumingReplacement || $this->rank($resumeFrom) < ClusterRouterReplacementStep::RouterCertificate->rank()) {
                $this->baselines->converge($node, $candidate);
            }
        } catch (Throwable $exception) {
            if (! $resumingReplacement && $routes->isEmpty()) {
                $candidate->delete();
            } else {
                $this->fail($candidate, 'baseline', $exception);
            }

            throw $exception;
        }

        try {
            if ($routes->isNotEmpty()) {
                $this->moveProjections($candidate, $routes, $node, $published);
                $published = $this->publicationCompleted($candidate);
            } else {
                $this->dnsSelection()->expand(
                    clusterOverrides: [$clusterId => ['router_node_id' => $node->id]],
                    clusterIds: [$clusterId],
                );
            }
        } catch (Throwable $exception) {
            if ($routes->isEmpty()) {
                $candidate->delete();

                throw $exception;
            }

            if (! $published) {
                $this->restoreProjections($candidate, $routes, $node, $oldRouter, $exception);
            } else {
                $this->fail($candidate, $this->stepName($exception, ClusterRouterReplacementStep::DnsPublished->value), $exception);
            }

            throw $exception;
        }

        try {
            if ($this->shouldRun($candidate, ClusterRouterReplacementStep::DatabaseCutover)) {
                DB::transaction(static function () use ($active, $candidate): void {
                    $active?->update(['status' => LifecycleStatus::Removing]);
                    $candidate->update(['status' => LifecycleStatus::Active, 'failed_step' => null, 'error_code' => null]);
                });
                $this->checkpoint($candidate, ClusterRouterReplacementStep::DatabaseCutover);
            }
        } catch (Throwable $exception) {
            if ($routes->isEmpty()) {
                $this->dnsSelection()->prune(clusterIds: [$clusterId]);
            } else {
                $this->fail($candidate, ClusterRouterReplacementStep::DatabaseCutover->value, $exception);
            }

            throw $exception;
        }

        try {
            if ($routes->isNotEmpty() && $oldRouter instanceof Node && $this->shouldRun($candidate, ClusterRouterReplacementStep::Cleanup)) {
                foreach ($routes as $route) {
                    $this->projector()->cleanupOldRouter($route, $oldRouter);
                }
                $this->checkpoint($candidate, ClusterRouterReplacementStep::Cleanup);
            }

            $this->finishOldCleanup($cluster, $candidate);
            $this->dnsSelection()->prune(clusterIds: [$clusterId]);
            $candidate->update(['failed_step' => null, 'error_code' => null]);
        } catch (Throwable $exception) {
            if ($routes->isNotEmpty()) {
                $this->fail($candidate, ClusterRouterReplacementStep::Cleanup->value, $exception);
            }

            throw $exception;
        }

        return $cluster->refresh();
    }

    /**
     * @param  Collection<int, Route>  $routes
     */
    private function moveProjections(
        NodeRole $candidate,
        Collection $routes,
        Node $router,
        bool $alreadyPublished,
    ): void {
        $this->forward($candidate, ClusterRouterReplacementStep::RouterCertificate, function () use ($routes, $router): void {
            foreach ($routes as $route) {
                foreach ($this->workloads($route) as $workload) {
                    $this->projector()->prepareRouterCertificate($route, $router, $workload);
                }
            }
        });
        $this->forward($candidate, ClusterRouterReplacementStep::FirewallPolicy, function () use ($routes, $router): void {
            foreach ($routes as $route) {
                foreach ($this->workloads($route) as $workload) {
                    $this->projector()->prepareFirewallPolicy($route, $router, $workload);
                }
            }
        });
        $this->forward($candidate, ClusterRouterReplacementStep::WorkloadVerified, function () use ($routes, $router): void {
            foreach ($routes as $route) {
                foreach ($this->workloads($route) as $workload) {
                    $this->projector()->verifyWorkload($route, $router, $workload);
                }
            }
        });
        $this->forward($candidate, ClusterRouterReplacementStep::RouterCaddy, function () use ($routes, $router): void {
            foreach ($routes as $route) {
                $this->projector()->prepareRouterCaddy($route, $router);
            }
        });
        $this->forward($candidate, ClusterRouterReplacementStep::DnsPublished, function () use ($routes, $router, $alreadyPublished): void {
            if (! $alreadyPublished) {
                $this->dnsSelection()->expand(
                    clusterOverrides: [$router->cluster_id => ['router_node_id' => $router->id]],
                    clusterIds: [(int) $router->cluster_id],
                );
            }

            foreach ($routes as $route) {
                $this->projector()->publishDns($route, $router);
            }
        });
    }

    /**
     * @param  Collection<int, Route>  $routes
     */
    private function restoreProjections(
        NodeRole $candidate,
        Collection $routes,
        Node $router,
        ?Node $oldRouter,
        Throwable $exception,
    ): void {
        $failedStep = $this->stepName($exception, ClusterRouterReplacementStep::RouterCertificate->value);

        try {
            foreach ($routes as $route) {
                $this->projector()->restore($route, $router, $oldRouter);
            }

            if ($oldRouter instanceof Node && is_int($oldRouter->cluster_id)) {
                $this->dnsSelection()->expand(
                    clusterOverrides: [$oldRouter->cluster_id => ['router_node_id' => $oldRouter->id]],
                    clusterIds: [$oldRouter->cluster_id],
                );
            }

            $this->fail($candidate, $failedStep, $exception);
        } catch (Throwable $rollback) {
            $this->fail($candidate, "rollback:{$failedStep}", $rollback);

            throw $rollback;
        }
    }

    private function forward(NodeRole $candidate, ClusterRouterReplacementStep $step, callable $operation): void
    {
        if (! $this->shouldRun($candidate, $step)) {
            return;
        }

        try {
            $operation();
        } catch (Throwable $exception) {
            throw $this->boundToStep($exception, $step);
        }

        $this->checkpoint($candidate, $step);
    }

    private function shouldRun(NodeRole $candidate, ClusterRouterReplacementStep $step): bool
    {
        $current = ClusterRouterReplacementStep::fromFailedStep($candidate->failed_step);

        if (! $current instanceof ClusterRouterReplacementStep) {
            return true;
        }

        if ($candidate->error_code !== null) {
            if ($current->rank() > ClusterRouterReplacementStep::DnsPublished->rank()) {
                return $step->rank() >= $current->rank();
            }

            return true;
        }

        return $step->rank() > $current->rank();
    }

    private function boundToStep(Throwable $exception, ClusterRouterReplacementStep $step): Throwable
    {
        if (! $exception instanceof RuntimeConvergenceException) {
            return $exception;
        }

        return new RuntimeConvergenceException(
            step: $step->value,
            errorCode: $exception->errorCode,
            message: $exception->getMessage(),
            previous: $exception,
            result: $exception->result,
        );
    }

    private function checkpoint(NodeRole $candidate, ClusterRouterReplacementStep $step): void
    {
        $candidate->update([
            'failed_step' => $step->value,
            'error_code' => null,
        ]);
        $candidate->refresh();
    }

    private function assertNoConflictingCandidate(int $clusterId, int $nodeId): void
    {
        $conflict = NodeRole::query()
            ->where('cluster_id', $clusterId)
            ->where('role', RoleName::Router)
            ->where('node_id', '!=', $nodeId)
            ->where(function ($query): void {
                $query
                    ->where(function ($query): void {
                        $query
                            ->where('status', LifecycleStatus::Provisioning)
                            ->whereNotNull('failed_step');
                    })
                    ->orWhere(function ($query): void {
                        $query
                            ->where('status', LifecycleStatus::Failed)
                            ->whereNotNull('failed_step');
                    })
                    ->orWhere(function ($query): void {
                        $query
                            ->where('status', LifecycleStatus::Active)
                            ->whereNotNull('failed_step');
                    });
            })
            ->get()
            ->first(static fn (NodeRole $assignment): bool => ClusterRouterReplacementStep::isReplacementProgress($assignment->failed_step));

        if ($conflict instanceof NodeRole) {
            throw new ResourceOperationException(
                errorCode: 'cluster.router_transition_conflict',
                message: 'Another Cluster Router replacement is in progress.',
                status: 409,
            );
        }
    }

    /** @return Collection<int, Route> */
    private function clusterRoutes(int $clusterId): Collection
    {
        return Route::query()
            ->with(['targets.appInstance.node', 'cluster'])
            ->where('cluster_id', $clusterId)
            ->whereIn('status', [
                RouteStatus::Active,
                RouteStatus::Activating,
                RouteStatus::Pending,
                RouteStatus::Failed,
            ])
            ->orderBy('id')
            ->get();
    }

    /** @return list<AppInstance> */
    private function workloads(Route $route): array
    {
        return $route
            ->targets
            ->map(static fn ($target) => $target->appInstance)
            ->filter(static fn ($target): bool => $target instanceof AppInstance)
            ->values()
            ->all();
    }

    private function finishOldCleanup(Cluster $cluster, NodeRole $current): void
    {
        $obsoleteAssignments = NodeRole::query()
            ->where('cluster_id', $cluster->id)
            ->where('role', RoleName::Router)
            ->whereKeyNot($current->id)
            ->orderBy('id')
            ->get();

        foreach ($obsoleteAssignments as $assignment) {
            try {
                $this->tearDown($assignment);
                $assignment->delete();
            } catch (Throwable $exception) {
                $this->fail($assignment, 'remove', $exception);
            }
        }
    }

    private function tearDown(NodeRole $assignment): void
    {
        if ($assignment->neverActivated()) {
            $this->baselines->removeUnreachable($assignment->node, $assignment);

            return;
        }

        $this->baselines->remove($assignment->node, $assignment, false);
    }

    private function fail(NodeRole $assignment, string $boundary, Throwable $exception): never
    {
        $step = property_exists($exception, 'step') && is_string($exception->step) ? $exception->step : $boundary;
        $errorCode = property_exists($exception, 'errorCode') && is_string($exception->errorCode)
            ? $exception->errorCode
            : 'node_role.operation_failed';

        if (ClusterRouterReplacementStep::isReplacementProgress($boundary) || str_starts_with($boundary, 'rollback:')) {
            $step = $boundary;
        } elseif ($boundary === 'remove' || $boundary === 'baseline') {
            $step = "{$boundary}:{$step}";
        }

        $assignment->update([
            'status' => $assignment->status === LifecycleStatus::Active
                ? LifecycleStatus::Active
                : LifecycleStatus::Failed,
            'failed_step' => $step,
            'error_code' => $errorCode,
        ]);

        throw $exception;
    }

    private function finishReplacementCleanup(Cluster $cluster, NodeRole $current): void
    {
        $completed = ClusterRouterReplacementStep::fromFailedStep($current->failed_step);

        if (
            ! $completed instanceof ClusterRouterReplacementStep
            || $completed->rank() < ClusterRouterReplacementStep::DatabaseCutover->rank()
        ) {
            return;
        }

        $oldRouter = NodeRole::query()
            ->where('cluster_id', $cluster->id)
            ->where('role', RoleName::Router)
            ->whereKeyNot($current->id)
            ->orderBy('id')
            ->get()
            ->map(static fn (NodeRole $assignment): ?Node => $assignment->node)
            ->first(static fn (?Node $node): bool => $node instanceof Node);

        if (! $oldRouter instanceof Node) {
            $current->update(['failed_step' => null, 'error_code' => null]);

            return;
        }

        foreach ($this->clusterRoutes($cluster->id) as $route) {
            $this->projector()->cleanupOldRouter($route, $oldRouter);
        }

        $current->update(['failed_step' => null, 'error_code' => null]);
    }

    private function publicationCompleted(NodeRole $candidate): bool
    {
        $completed = ClusterRouterReplacementStep::fromFailedStep($candidate->failed_step);

        if (! $completed instanceof ClusterRouterReplacementStep) {
            return false;
        }

        if ($completed->rank() > ClusterRouterReplacementStep::DnsPublished->rank()) {
            return true;
        }

        return $completed === ClusterRouterReplacementStep::DnsPublished && $candidate->error_code === null;
    }

    private function stepName(Throwable $exception, string $fallback): string
    {
        if (property_exists($exception, 'step') && is_string($exception->step) && ClusterRouterReplacementStep::isReplacementProgress($exception->step)) {
            return $exception->step;
        }

        return $fallback;
    }

    private function rank(?ClusterRouterReplacementStep $step): int
    {
        return $step?->rank() ?? 0;
    }

    private function dnsSelection(): ClusterRouterDnsSelectionReconciler
    {
        return $this->dnsSelection ?? app(ClusterRouterDnsSelectionReconciler::class);
    }

    private function projector(): ClusterRouterReplacementProjector
    {
        return $this->replacements ?? app(ClusterRouterReplacementProjector::class);
    }
}
