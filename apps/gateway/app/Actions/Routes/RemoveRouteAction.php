<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Nodes\NodeReachabilityProbe;
use App\Domain\Routes\PublicRouteEligibility;
use App\Domain\Routes\RouteAssociationGuard;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteReconciliationGuard;
use App\Domain\Routes\RouteRemovalNode;
use App\Domain\Routes\RouteRemovalProjector;
use App\Domain\Routes\RouteRemovalStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Shared\StoredInteger;
use App\Models\Route;
use App\Models\RouteRemovalResidue;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class RemoveRouteAction
{
    public function __construct(
        private InstanceEnvironmentOperationLock $environmentOperations,
        private DevelopmentProjectionOperationLock $owner,
        private RouteAssociationGuard $associations,
        private RouteReconciliationGuard $reconciliation,
        private RouteRemovalProjector $projection,
        private ?RecordEventBroadcaster $broadcaster = null,
        private ?MetricsFleetReconciler $metrics = null,
        private ?NodeReachabilityProbe $reachability = null,
    ) {}

    /**
     * With `$offline`, a Node the removal would change that is not active, or that the probe finds
     * unreachable, is left unchanged. The Route is still deleted, and each skipped Node keeps a
     * {@see RouteRemovalResidue} that Doctor reports and the Node's next converge removes.
     */
    public function execute(Route $route, bool $offline = false): Route
    {
        return $this->remove($route, allowTracking: false, offline: $offline);
    }

    public function executeTrackingRoute(Route $route): Route
    {
        if ($route->kind !== RouteKind::AnalyticsTracking) {
            throw new ResourceOperationException(
                errorCode: 'route.kind_invalid',
                message: 'Only analytics tracking Routes can use the analytics removal path.',
                status: 409,
            );
        }

        return $this->remove($route, allowTracking: true, offline: false);
    }

    private function remove(Route $route, bool $allowTracking, bool $offline): Route
    {
        $expectedTargetIds = $route
            ->targets()
            ->orderBy('instance_id')
            ->pluck('instance_id')
            ->map(static fn (mixed $id): int => StoredInteger::from($id))
            ->values()
            ->all();
        $expectedTargetIds = array_values($expectedTargetIds);
        $result = $this->environmentOperations->run(
            $expectedTargetIds,
            fn (): Route => $this->owner->run(
                fn (): Route => $this->executeOwned($route, $expectedTargetIds, $allowTracking, $offline),
            ),
        );

        ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
            RecordEventType::RouteDeleted,
            $result->id,
            ['id' => $result->id, 'domain' => $result->domain],
        );

        $this->metrics?->reconcile();

        return $result;
    }

    /**
     * A targeted Route can be removed once none of its Instances is active. It withdraws its
     * projections like an untargeted Route, and also converges PHP-FPM on its target Nodes, so a
     * pending Route that published its sites leaves no site, certificate, DNS name, firewall rule,
     * or pool behind.
     *
     * @param  list<int>  $expectedTargetIds
     */
    private function executeOwned(Route $route, array $expectedTargetIds, bool $allowTracking, bool $offline): Route
    {
        $locked = $this->lockAndGuard($route, $expectedTargetIds);
        $targeted = $locked->targets->isNotEmpty();

        if ($targeted) {
            if (RouteRemovalStep::isUntargetedFailure($locked->failed_step)) {
                throw new ResourceOperationException(
                    errorCode: 'env.owner_changed',
                    message: 'The Instance environment owner changed during the operation.',
                    status: 409,
                );
            }

            $this->associations->assertTargetsDetachable($locked);
            $this->reconciliation->assertRouteMutable($locked);
        }

        $this->assertStandaloneRemovalAllowed($locked, $allowTracking);
        $nodes = $this->projection->nodes($locked);
        // The probe runs before anything changes, so a skipped Node is never a swallowed failure.
        $skipped = $offline ? $this->unavailable($nodes) : [];
        $skippedNodeIds = array_map(static fn (RouteRemovalNode $node): int => $node->node->id, $skipped);
        $this->beginRemoval($locked);

        try {
            $failureStep = RouteRemovalStep::Dns;
            $this->cleanupStep($locked, $failureStep, function () use ($locked): void {
                $this->projection->cleanupDns($locked);
            });
            $failureStep = RouteRemovalStep::Caddy;
            $this->cleanupStep($locked, $failureStep, function () use ($locked, $skippedNodeIds): void {
                $this->projection->cleanupCaddy($locked, $skippedNodeIds);
            });

            // The site is gone once Caddy builds, so its pool leaves next.
            if ($targeted) {
                $failureStep = RouteRemovalStep::Php;
                $this->cleanupStep($locked, $failureStep, function () use ($locked, $skippedNodeIds): void {
                    $this->projection->cleanupPhp($locked, $skippedNodeIds);
                });
            }

            $failureStep = RouteRemovalStep::Certificates;
            $this->cleanupStep($locked, $failureStep, function () use ($locked, $skippedNodeIds): void {
                $this->projection->cleanupCertificates($locked, $skippedNodeIds);
            });
            $failureStep = RouteRemovalStep::Firewall;
            $this->cleanupStep($locked, $failureStep, function () use ($locked, $skippedNodeIds): void {
                $this->projection->cleanupFirewall($locked, $skippedNodeIds);
            });
            $failureStep = RouteRemovalStep::Record;

            return $this->deleteRecord($locked, $expectedTargetIds, $allowTracking, $skipped);
        } catch (Throwable $exception) {
            if (! $offline && $this->changesNodes($failureStep)) {
                $exception = $this->unreachableFailure($exception, $nodes, $failureStep);
            }

            $this->recordFailure($locked, $failureStep->failedStep($targeted), $this->errorCode($exception));

            throw $exception;
        }
    }

    /** @param list<int> $expectedTargetIds */
    private function lockAndGuard(Route $route, array $expectedTargetIds): Route
    {
        $locked = DB::transaction(function () use ($route, $expectedTargetIds): Route {
            $locked = Route::query()->with('targets')->lockForUpdate()->findOrFail($route->id);
            $this->assertTargetsUnchanged($locked, $expectedTargetIds);

            return $locked;
        });

        return $locked;
    }

    private function assertStandaloneRemovalAllowed(Route $route, bool $allowTracking): void
    {
        if ($route->kind === RouteKind::AnalyticsTracking && ! $allowTracking) {
            throw new ResourceOperationException(
                errorCode: 'route.tracking_managed',
                message: 'Tracking Routes are managed by the analytics role and cannot be destroyed directly.',
                status: 409,
            );
        }

        if (new PublicRouteEligibility()->publicEdgeIsLive($route)) {
            $this->reconciliation->refuse();
        }

        if ($route->replaces_route_id !== null || $route->replaced_by_route_id !== null) {
            $this->reconciliation->refuse();
        }
    }

    /**
     * Removal clears the publication record before the build that withdraws the Route's sites,
     * and the Route leaves the authoritative states with it.
     */
    private function beginRemoval(Route $route): void
    {
        if ($route->status === RouteStatus::Retiring && ! $route->sites_published) {
            return;
        }

        DB::transaction(function () use ($route): void {
            $locked = Route::query()->lockForUpdate()->findOrFail($route->id);

            if ($locked->status === RouteStatus::Retiring && ! $locked->sites_published) {
                $route->setRawAttributes($locked->refresh()->getAttributes(), true);

                return;
            }

            $locked->update([
                'status' => RouteStatus::Retiring,
                'sites_published' => false,
            ]);
            $route->setRawAttributes($locked->refresh()->getAttributes(), true);
        });
    }

    private function cleanupStep(Route $route, RouteRemovalStep $step, callable $operation): void
    {
        $operation();
        $this->checkpoint($route, $step);
    }

    private function checkpoint(Route $route, RouteRemovalStep $step): void
    {
        if ($this->rank($step) < $this->rank($this->resumeFrom($route->failed_step))) {
            return;
        }

        if ($route->failed_step === null && $route->error_code === null) {
            return;
        }

        DB::transaction(function () use ($route): void {
            $locked = Route::query()->lockForUpdate()->findOrFail($route->id);
            $locked->update([
                'failed_step' => null,
                'error_code' => null,
            ]);
            $route->setRawAttributes($locked->refresh()->getAttributes(), true);
        });
    }

    /**
     * The Node is not active, or it is active and does not answer. An active Node that answers keeps
     * every removal step, and a failure on it still fails closed.
     *
     * @param  list<RouteRemovalNode>  $nodes
     * @return list<RouteRemovalNode>
     */
    private function unavailable(array $nodes): array
    {
        $reachability = $this->reachability ?? app(NodeReachabilityProbe::class);

        return array_values(array_filter(
            $nodes,
            static fn (RouteRemovalNode $node): bool => $node->node->status !== LifecycleStatus::Active
                || $reachability->degradation($node->node) === ExporterDegradationReason::Unreachable,
        ));
    }

    private function changesNodes(RouteRemovalStep $step): bool
    {
        return in_array($step, [
            RouteRemovalStep::Caddy,
            RouteRemovalStep::Php,
            RouteRemovalStep::Certificates,
            RouteRemovalStep::Firewall,
        ], true);
    }

    /**
     * Names the Nodes that did not answer and the option that removes the Route without them. A failure
     * on Nodes that all answer stays as it is.
     *
     * @param  list<RouteRemovalNode>  $nodes
     */
    private function unreachableFailure(Throwable $exception, array $nodes, RouteRemovalStep $step): Throwable
    {
        $acting = array_values(array_filter(
            $nodes,
            static fn (RouteRemovalNode $node): bool => in_array($step, $node->steps, true),
        ));
        $unavailable = $this->unavailable($acting);

        if ($unavailable === []) {
            return $exception;
        }

        $names = array_map(static fn (RouteRemovalNode $node): string => $node->node->name, $unavailable);
        $list = implode('], [', $names);

        return new ResourceOperationException(
            errorCode: 'route.node_unreachable',
            message: "Route removal could not change node [{$list}], which is unreachable or not active. Retry with --offline to remove the Route and leave that Node unchanged until its next converge.",
            status: 502,
            previous: $exception,
            details: [
                'nodes' => implode(', ', $names),
                'step' => $step->value,
                'underlying_error_code' => $this->errorCode($exception),
            ],
        );
    }

    /**
     * @param  list<int>  $expectedTargetIds
     * @param  list<RouteRemovalNode>  $skipped
     */
    private function deleteRecord(Route $route, array $expectedTargetIds, bool $allowTracking, array $skipped): Route
    {
        $removed = DB::transaction(function () use ($route, $expectedTargetIds, $allowTracking, $skipped): Route {
            $locked = Route::query()->with('targets')->lockForUpdate()->findOrFail($route->id);
            $this->assertTargetsUnchanged($locked, $expectedTargetIds);

            if ($locked->targets->isNotEmpty()) {
                $this->associations->assertTargetsDetachable($locked);
            }

            $this->assertStandaloneRemovalAllowed($locked, $allowTracking);
            $locked->delete();

            foreach ($skipped as $node) {
                RouteRemovalResidue::query()->updateOrCreate(
                    ['node_id' => $node->node->id, 'route_id' => $locked->id],
                    [
                        'domain' => $locked->domain,
                        'steps' => array_map(static fn (RouteRemovalStep $step): string => $step->value, $node->steps),
                    ],
                );
            }

            return $locked;
        });

        return $removed;
    }

    /** @param list<int> $expectedTargetIds */
    private function assertTargetsUnchanged(Route $route, array $expectedTargetIds): void
    {
        $currentTargetIds = $route
            ->targets
            ->pluck('instance_id')
            ->map(static fn (mixed $id): int => StoredInteger::from($id))
            ->sort()
            ->values()
            ->all();
        $expectedSorted = $expectedTargetIds;
        sort($expectedSorted);

        if ($currentTargetIds !== $expectedSorted) {
            throw new ResourceOperationException(
                errorCode: 'env.owner_changed',
                message: 'The Instance environment owner changed during the operation.',
                status: 409,
            );
        }
    }

    private function recordFailure(Route $route, string $failedStep, string $errorCode): void
    {
        Route::query()
            ->whereKey($route->id)
            ->update([
                'status' => RouteStatus::Failed->value,
                'failed_step' => $failedStep,
                'error_code' => $errorCode,
            ]);
        $route->refresh();
    }

    private function resumeFrom(?string $failedStep): RouteRemovalStep
    {
        return RouteRemovalStep::fromFailedStep($failedStep) ?? RouteRemovalStep::Dns;
    }

    private function errorCode(Throwable $exception): string
    {
        return property_exists($exception, 'errorCode') && is_string($exception->errorCode)
            ? $exception->errorCode
            : 'route.removal_failed';
    }

    private function rank(RouteRemovalStep $step): int
    {
        return match ($step) {
            RouteRemovalStep::Dns => 0,
            RouteRemovalStep::Caddy => 1,
            RouteRemovalStep::Php => 2,
            RouteRemovalStep::Certificates => 3,
            RouteRemovalStep::Firewall => 4,
            RouteRemovalStep::Record => 5,
        };
    }
}
