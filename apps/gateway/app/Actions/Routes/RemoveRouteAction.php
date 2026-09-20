<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Routes\PublicRouteEligibility;
use App\Domain\Routes\RouteAssociationGuard;
use App\Domain\Routes\RouteReconciliationGuard;
use App\Domain\Routes\RouteRemovalProjector;
use App\Domain\Routes\RouteRemovalStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Route;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class RemoveRouteAction
{
    public function __construct(
        private AppInstanceEnvironmentOperationLock $environmentOperations,
        private DevelopmentProjectionOperationLock $owner,
        private RouteAssociationGuard $associations,
        private RouteReconciliationGuard $reconciliation,
        private RouteRemovalProjector $projection,
        private ?RecordEventBroadcaster $broadcaster = null,
        private ?MetricsFleetReconciler $metrics = null,
    ) {}

    public function execute(Route $route): Route
    {
        /** @var list<int> $expectedTargetIds */
        $expectedTargetIds = $route
            ->targets()
            ->orderBy('app_instance_id')
            ->pluck('app_instance_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        $result = $this->environmentOperations->run(
            $expectedTargetIds,
            fn (): Route => $this->owner->run(
                fn (): Route => $this->executeOwned($route, $expectedTargetIds),
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

    /** @param list<int> $expectedTargetIds */
    private function executeOwned(Route $route, array $expectedTargetIds): Route
    {
        $locked = $this->lockAndGuard($route, $expectedTargetIds);

        if ($locked->targets->isNotEmpty()) {
            if (RouteRemovalStep::tryFrom((string) $locked->failed_step) instanceof RouteRemovalStep) {
                throw new ResourceOperationException(
                    errorCode: 'env.owner_changed',
                    message: 'The AppInstance environment owner changed during the operation.',
                    status: 409,
                );
            }

            $this->associations->assertTargetsDetachable($locked);
            $this->reconciliation->assertRouteMutable($locked);
            $locked->delete();

            return $locked;
        }

        $this->assertStandaloneRemovalAllowed($locked);
        $this->beginRemoval($locked);

        try {
            $failureStep = RouteRemovalStep::Dns;
            $this->cleanupStep($locked, $failureStep, function () use ($locked): void {
                $this->projection->cleanupDns($locked);
            });
            $failureStep = RouteRemovalStep::Certificates;
            $this->cleanupStep($locked, $failureStep, function () use ($locked): void {
                $this->projection->cleanupCertificates($locked);
            });
            $failureStep = RouteRemovalStep::Caddy;
            $this->cleanupStep($locked, $failureStep, function () use ($locked): void {
                $this->projection->cleanupCaddy($locked);
            });
            $failureStep = RouteRemovalStep::Firewall;
            $this->cleanupStep($locked, $failureStep, function () use ($locked): void {
                $this->projection->cleanupFirewall($locked);
            });
            $failureStep = RouteRemovalStep::Record;

            return $this->deleteRecord($locked, $expectedTargetIds);
        } catch (Throwable $exception) {
            $this->recordFailure($locked, $failureStep, $this->errorCode($exception));

            throw $exception;
        }
    }

    /** @param list<int> $expectedTargetIds */
    private function lockAndGuard(Route $route, array $expectedTargetIds): Route
    {
        /** @var Route $locked */
        $locked = DB::transaction(function () use ($route, $expectedTargetIds): Route {
            $locked = Route::query()->with('targets')->lockForUpdate()->findOrFail($route->id);
            $this->assertTargetsUnchanged($locked, $expectedTargetIds);

            return $locked;
        });

        return $locked;
    }

    private function assertStandaloneRemovalAllowed(Route $route): void
    {
        if (new PublicRouteEligibility()->publicEdgeIsLive($route)) {
            $this->reconciliation->refuse();
        }

        if ($route->replaces_route_id !== null || $route->replaced_by_route_id !== null) {
            $this->reconciliation->refuse();
        }
    }

    private function beginRemoval(Route $route): void
    {
        if ($route->status === RouteStatus::Retiring) {
            return;
        }

        DB::transaction(function () use ($route): void {
            $locked = Route::query()->lockForUpdate()->findOrFail($route->id);

            if ($locked->status === RouteStatus::Retiring) {
                $route->setRawAttributes($locked->refresh()->getAttributes(), true);

                return;
            }

            $locked->update([
                'status' => RouteStatus::Retiring,
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

    /** @param list<int> $expectedTargetIds */
    private function deleteRecord(Route $route, array $expectedTargetIds): Route
    {
        /** @var Route $removed */
        $removed = DB::transaction(function () use ($route, $expectedTargetIds): Route {
            $locked = Route::query()->with('targets')->lockForUpdate()->findOrFail($route->id);
            $this->assertTargetsUnchanged($locked, $expectedTargetIds);
            $this->assertStandaloneRemovalAllowed($locked);
            $locked->delete();

            return $locked;
        });

        return $removed;
    }

    /** @param list<int> $expectedTargetIds */
    private function assertTargetsUnchanged(Route $route, array $expectedTargetIds): void
    {
        $currentTargetIds = $route
            ->targets
            ->pluck('app_instance_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->sort()
            ->values()
            ->all();
        $expectedSorted = $expectedTargetIds;
        sort($expectedSorted);

        if ($currentTargetIds !== $expectedSorted) {
            throw new ResourceOperationException(
                errorCode: 'env.owner_changed',
                message: 'The AppInstance environment owner changed during the operation.',
                status: 409,
            );
        }
    }

    private function recordFailure(Route $route, RouteRemovalStep $step, string $errorCode): void
    {
        Route::query()
            ->whereKey($route->id)
            ->update([
                'status' => RouteStatus::Failed->value,
                'failed_step' => $step->value,
                'error_code' => $errorCode,
            ]);
        $route->refresh();
    }

    private function resumeFrom(?string $failedStep): RouteRemovalStep
    {
        return RouteRemovalStep::tryFrom((string) $failedStep) ?? RouteRemovalStep::Dns;
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
            RouteRemovalStep::Certificates => 1,
            RouteRemovalStep::Caddy => 2,
            RouteRemovalStep::Firewall => 3,
            RouteRemovalStep::Record => 4,
        };
    }
}
