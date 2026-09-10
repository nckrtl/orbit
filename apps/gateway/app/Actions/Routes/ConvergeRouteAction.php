<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\DevelopmentAppInstanceConfigurator;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\Routes\RouteHostnameChangeDirection;
use App\Domain\Routes\RouteHostnameChangeStep;
use App\Domain\Routes\RouteHostnameProjector;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteReconciliationGuard;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use App\Models\Route;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class ConvergeRouteAction
{
    public function __construct(
        private RouteHostnameProjector $projection,
        private DevelopmentAppInstanceConfigurator $configuration,
        private AppInstanceEnvironmentOperationLock $environmentOperations,
        private DevelopmentProjectionOperationLock $owner,
    ) {}

    public function execute(Route $route, string $hostname): Route
    {
        /** @var list<int> $targetIds */
        $targetIds = $route
            ->targets()
            ->orderBy('app_instance_id')
            ->pluck('app_instance_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        return $this->environmentOperations->run(
            $targetIds,
            fn (): Route => $this->owner->run(
                fn (): Route => $this->convergeOwned($route->id, $hostname, $targetIds),
            ),
        );
    }

    /** @param list<int> $expectedTargetIds */
    private function convergeOwned(int $routeId, string $hostname, array $expectedTargetIds): Route
    {
        $route = Route::query()
            ->with(['targets.appInstance.app', 'targets.appInstance.node', 'cluster.routerAssignment.node'])
            ->findOrFail($routeId);

        $currentTargetIds = $route
            ->targets
            ->pluck('app_instance_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        if ($currentTargetIds !== $expectedTargetIds) {
            throw new ResourceOperationException(
                errorCode: 'env.owner_changed',
                message: 'The AppInstance environment owner changed during the operation.',
                status: 409,
            );
        }

        if ($route->hostname === $hostname && $route->hostname_change_target === null) {
            return $route;
        }

        $appInstance = $this->eligibleTarget($route);
        $route = $this->reserve($route, $hostname);

        if ($route->hostname_change_direction === RouteHostnameChangeDirection::Rollback) {
            $this->rollback($route, $appInstance);
            $route = $this->restart($route);
        }

        if ($route->hostname_change_step === RouteHostnameChangeStep::DatabaseCutover) {
            return $this->cleanup($route, $appInstance, revalidate: true);
        }

        $candidate = $this->candidate($route);
        $failureStep = 'workload-certificate';

        try {
            $this->forwardStep(
                $route,
                RouteHostnameChangeStep::WorkloadCertificate,
                fn () => $this->projection->prepareWorkloadCertificate($appInstance, $route, $candidate),
            );
            $failureStep = 'workload-caddy';
            $this->forwardStep(
                $route,
                RouteHostnameChangeStep::WorkloadCaddy,
                fn () => $this->projection->prepareWorkloadCaddy($appInstance, $route, $candidate),
            );
            $failureStep = 'router-certificate';
            $this->forwardStep(
                $route,
                RouteHostnameChangeStep::RouterCertificate,
                fn () => $this->projection->prepareRouterCertificate($appInstance, $route, $candidate),
            );
            $failureStep = 'firewall-policy';
            $this->forwardStep(
                $route,
                RouteHostnameChangeStep::FirewallPolicy,
                fn () => $this->projection->prepareFirewallPolicy($appInstance, $candidate),
            );
            $failureStep = 'workload-verify';
            $this->forwardStep(
                $route,
                RouteHostnameChangeStep::WorkloadVerified,
                fn () => $this->projection->verifyWorkload($appInstance, $candidate),
            );
            $failureStep = 'router-caddy';
            $this->forwardStep(
                $route,
                RouteHostnameChangeStep::RouterCaddy,
                fn () => $this->projection->prepareRouterCaddy($appInstance, $route, $candidate),
            );
            $failureStep = 'laravel-url';
            $this->forwardStep(
                $route,
                RouteHostnameChangeStep::LaravelUrl,
                function () use ($appInstance, $candidate): void {
                    if ($appInstance->source_is_laravel) {
                        $this->configuration->configureLaravelUrl(
                            $appInstance,
                            "https://{$candidate->hostname}",
                        );
                    }
                },
            );
            $failureStep = 'dns-publication';
            $this->forwardStep(
                $route,
                RouteHostnameChangeStep::DnsPublished,
                fn () => $this->projection->publishDns($route, $candidate),
            );
            $failureStep = 'database-cutover';
            $this->databaseCutover($route);
        } catch (Throwable $exception) {
            $this->recordFailure($route, $failureStep, $this->errorCode($exception));
            $this->beginRollback($route);

            try {
                $this->rollback($route->refresh(), $appInstance);
            } catch (Throwable $rollbackException) {
                throw $rollbackException;
            }

            throw $exception;
        }

        return $this->cleanup($route->refresh(), $appInstance);
    }

    private function eligibleTarget(Route $route): AppInstance
    {
        if ($route->targets->count() !== 1) {
            app(RouteReconciliationGuard::class)->refuse();
        }

        $target = $route->targets->firstOrFail()->appInstance;

        if (
            $route->status !== RouteStatus::Active
            || $route->provenance !== RouteProvenance::Explicit
            || $route->publication !== RoutePublication::Private
            || $target->status !== AppInstanceState::Active
            || $target->environment !== 'development'
            || $target->source_is_laravel === null
        ) {
            app(RouteReconciliationGuard::class)->refuse();
        }

        return $target;
    }

    private function reserve(Route $route, string $hostname): Route
    {
        /** @var Route $reserved */
        $reserved = DB::transaction(function () use ($route, $hostname): Route {
            $locked = Route::query()->lockForUpdate()->findOrFail($route->id);

            if ($locked->hostname_change_target !== null) {
                if ($locked->hostname_change_target !== $hostname) {
                    throw new ResourceOperationException(
                        errorCode: 'route.hostname_change_conflict',
                        message: 'The Route already has another hostname change in progress.',
                        status: 409,
                    );
                }

                return $locked->refresh();
            }

            $occupied = Route::query()
                ->whereKeyNot($locked->id)
                ->where(
                    static fn ($query) => $query
                        ->where('hostname', $hostname)
                        ->orWhere('hostname_change_target', $hostname),
                )
                ->exists();

            if ($occupied) {
                throw new ResourceOperationException(
                    errorCode: 'route.hostname_conflict',
                    message: 'The Route hostname is already owned.',
                    status: 409,
                );
            }

            $locked->update([
                'hostname_change_previous' => $locked->hostname,
                'hostname_change_target' => $hostname,
                'hostname_change_direction' => RouteHostnameChangeDirection::Forward,
                'hostname_change_step' => RouteHostnameChangeStep::Reserved,
                'failed_step' => null,
                'error_code' => null,
            ]);

            return $locked->refresh();
        });

        return $reserved;
    }

    private function restart(Route $route): Route
    {
        if ($route->hostname_change_step !== RouteHostnameChangeStep::RolledBack) {
            throw new ResourceOperationException(
                errorCode: 'route.hostname_change_rollback_incomplete',
                message: 'The previous hostname change rollback is incomplete.',
                status: 409,
            );
        }

        $route->update([
            'hostname_change_direction' => RouteHostnameChangeDirection::Forward,
            'hostname_change_step' => RouteHostnameChangeStep::Reserved,
            'failed_step' => null,
            'error_code' => null,
        ]);

        return $route->refresh();
    }

    private function candidate(Route $route): Route
    {
        $candidate = clone $route;
        $candidate->hostname = (string) $route->hostname_change_target;

        return $candidate;
    }

    private function forwardStep(Route $route, RouteHostnameChangeStep $step, callable $operation): void
    {
        $operation();

        if ($this->forwardRank($route->hostname_change_step) < $this->forwardRank($step)) {
            $this->checkpoint($route, RouteHostnameChangeDirection::Forward, $step);
        }
    }

    private function databaseCutover(Route $route): void
    {
        DB::transaction(function () use ($route): void {
            $locked = Route::query()->lockForUpdate()->findOrFail($route->id);
            $this->assertOperation($locked, RouteHostnameChangeDirection::Forward);
            $locked->update([
                'hostname' => $locked->hostname_change_target,
                'hostname_change_step' => RouteHostnameChangeStep::DatabaseCutover,
                'failed_step' => null,
                'error_code' => null,
            ]);
        });
    }

    private function cleanup(Route $route, AppInstance $appInstance, bool $revalidate = false): Route
    {
        try {
            if ($revalidate) {
                $candidate = $this->candidate($route);
                $this->projection->prepareFirewallPolicy($appInstance, $candidate);

                if ($appInstance->source_is_laravel) {
                    $this->configuration->configureLaravelUrl(
                        $appInstance,
                        "https://{$candidate->hostname}",
                    );
                }
            }

            $this->projection->cleanup($appInstance, $route);

            if ($revalidate) {
                $this->projection->verifyWorkload($appInstance, $this->candidate($route));
            }

            $route->update([
                'hostname_change_previous' => null,
                'hostname_change_target' => null,
                'hostname_change_direction' => null,
                'hostname_change_step' => null,
                'failed_step' => null,
                'error_code' => null,
            ]);
        } catch (Throwable $exception) {
            $this->recordFailure($route, 'cleanup', $this->errorCode($exception));

            throw $exception;
        }

        return $route->refresh()->load('targets');
    }

    private function beginRollback(Route $route): void
    {
        $route
            ->refresh()
            ->update([
                'hostname_change_direction' => RouteHostnameChangeDirection::Rollback,
                'hostname_change_step' => RouteHostnameChangeStep::RollbackPending,
            ]);
    }

    private function rollback(Route $route, AppInstance $appInstance): void
    {
        $failureStep = 'rollback-dns';

        try {
            $this->rollbackStep(
                $route,
                RouteHostnameChangeStep::RollbackDns,
                fn () => $this->projection->rollbackDns($route),
            );
            $failureStep = 'rollback-caddy';
            $this->rollbackStep(
                $route,
                RouteHostnameChangeStep::RollbackCaddy,
                fn () => $this->projection->rollbackCaddy($appInstance, $route),
            );
            $failureStep = 'rollback-certificates';
            $this->rollbackStep(
                $route,
                RouteHostnameChangeStep::RollbackCertificates,
                fn () => $this->projection->rollbackCertificates($appInstance, $route),
            );
            $failureStep = 'rollback-laravel-url';
            $this->rollbackStep(
                $route,
                RouteHostnameChangeStep::RollbackLaravelUrl,
                function () use ($route, $appInstance): void {
                    if ($appInstance->source_is_laravel) {
                        $this->configuration->configureLaravelUrl(
                            $appInstance,
                            "https://{$route->hostname_change_previous}",
                        );
                    }
                },
            );
            $failureStep = 'rollback';
            $this->checkpoint(
                $route,
                RouteHostnameChangeDirection::Rollback,
                RouteHostnameChangeStep::RolledBack,
                clearFailure: false,
            );
        } catch (Throwable $exception) {
            $this->recordFailure($route, $failureStep, $this->errorCode($exception));

            throw $exception;
        }
    }

    private function rollbackStep(Route $route, RouteHostnameChangeStep $step, callable $operation): void
    {
        $operation();

        if ($this->rollbackRank($route->hostname_change_step) < $this->rollbackRank($step)) {
            $this->checkpoint(
                $route,
                RouteHostnameChangeDirection::Rollback,
                $step,
                clearFailure: false,
            );
        }
    }

    private function checkpoint(
        Route $route,
        RouteHostnameChangeDirection $direction,
        RouteHostnameChangeStep $step,
        bool $clearFailure = true,
    ): void {
        DB::transaction(function () use ($route, $direction, $step, $clearFailure): void {
            $locked = Route::query()->lockForUpdate()->findOrFail($route->id);
            $this->assertOperation($locked, $direction);
            $attributes = ['hostname_change_step' => $step];

            if ($clearFailure) {
                $attributes['failed_step'] = null;
                $attributes['error_code'] = null;
            }

            $locked->update($attributes);
            $route->setRawAttributes($locked->getAttributes(), true);
        });
    }

    private function assertOperation(Route $route, RouteHostnameChangeDirection $direction): void
    {
        if (
            $route->hostname_change_target === null
            || $route->hostname_change_previous === null
            || $route->hostname_change_direction !== $direction
        ) {
            throw new ResourceOperationException(
                errorCode: 'route.hostname_change_conflict',
                message: 'The Route hostname change evidence changed during convergence.',
                status: 409,
            );
        }
    }

    private function recordFailure(Route $route, string $step, string $errorCode): void
    {
        Route::query()
            ->whereKey($route->id)
            ->update([
                'failed_step' => $step,
                'error_code' => $errorCode,
            ]);
        $route->refresh();
    }

    private function errorCode(Throwable $exception): string
    {
        return property_exists($exception, 'errorCode') && is_string($exception->errorCode)
            ? $exception->errorCode
            : 'route.hostname_change_failed';
    }

    private function forwardRank(?RouteHostnameChangeStep $step): int
    {
        return match ($step) {
            RouteHostnameChangeStep::Reserved => 0,
            RouteHostnameChangeStep::WorkloadCertificate => 1,
            RouteHostnameChangeStep::WorkloadCaddy => 2,
            RouteHostnameChangeStep::RouterCertificate => 3,
            RouteHostnameChangeStep::FirewallPolicy => 4,
            RouteHostnameChangeStep::WorkloadVerified => 5,
            RouteHostnameChangeStep::RouterCaddy => 6,
            RouteHostnameChangeStep::LaravelUrl => 7,
            RouteHostnameChangeStep::DnsPublished => 8,
            RouteHostnameChangeStep::DatabaseCutover => 9,
            default => -1,
        };
    }

    private function rollbackRank(?RouteHostnameChangeStep $step): int
    {
        return match ($step) {
            RouteHostnameChangeStep::RollbackPending => 0,
            RouteHostnameChangeStep::RollbackDns => 1,
            RouteHostnameChangeStep::RollbackCaddy => 2,
            RouteHostnameChangeStep::RollbackCertificates => 3,
            RouteHostnameChangeStep::RollbackLaravelUrl => 4,
            RouteHostnameChangeStep::RolledBack => 5,
            default => -1,
        };
    }
}
