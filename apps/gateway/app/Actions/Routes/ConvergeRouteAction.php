<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppInstances\AppInstanceSourceProfileGuard;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\DevelopmentAppInstanceConfigurator;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentRouteDomain;
use App\Domain\AppInstances\Environment\AppInstanceRouteEnvironmentSynchronizer;
use App\Domain\Routes\RouteDomain;
use App\Domain\Routes\RouteDomainProjector;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RoutePublicPublication;
use App\Domain\Routes\RouteReconciliationGuard;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use App\Models\Route;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class ConvergeRouteAction
{
    public function __construct(
        private RouteDomainProjector $projection,
        private DevelopmentAppInstanceConfigurator $configuration,
        private AppInstanceRouteEnvironmentSynchronizer $routeEnvironment,
        private AppInstanceEnvironmentOperationLock $environmentOperations,
        private DevelopmentProjectionOperationLock $owner,
    ) {}

    public function execute(
        Route $route,
        string $domain,
        ?RoutePublication $publication = null,
        bool $allowGenerated = false,
    ): Route {
        $domain = RouteDomain::validate($domain);

        /** @var list<int> $targetIds */
        $targetIds = $route
            ->targets()
            ->orderBy('position')
            ->orderBy('app_instance_id')
            ->pluck('app_instance_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        return $this->environmentOperations->run(
            $targetIds,
            fn (): Route => $this->owner->run(
                fn (): Route => $this->convergeOwned(
                    $route->id,
                    $domain,
                    $targetIds,
                    $publication,
                    $allowGenerated,
                ),
            ),
        );
    }

    /** @param list<int> $expectedTargetIds */
    private function convergeOwned(
        int $routeId,
        string $domain,
        array $expectedTargetIds,
        ?RoutePublication $publication = null,
        bool $allowGenerated = false,
    ): Route {
        $route = Route::query()
            ->with(['targets.appInstance.app', 'targets.appInstance.node', 'cluster.routerAssignment.node'])
            ->findOrFail($routeId);

        if ($route->replaces_route_id !== null) {
            $current = Route::query()
                ->with(['targets.appInstance.app', 'targets.appInstance.node', 'cluster.routerAssignment.node'])
                ->find($route->replaces_route_id);

            if ($current instanceof Route) {
                if ($route->domain !== $domain) {
                    throw new ResourceOperationException(
                        errorCode: 'route.domain_change_conflict',
                        message: 'The Route already has another domain change in progress.',
                        status: 409,
                    );
                }

                return $this->convergeOwned(
                    $current->id,
                    $domain,
                    $expectedTargetIds,
                    $publication,
                    $allowGenerated,
                );
            }
        }

        if ($route->status === RouteStatus::Retiring && $route->replaced_by_route_id !== null) {
            $replacement = Route::query()
                ->with(['targets.appInstance.app', 'targets.appInstance.node'])
                ->findOrFail($route->replaced_by_route_id);

            if ($replacement->domain !== $domain) {
                throw new ResourceOperationException(
                    errorCode: 'route.domain_change_conflict',
                    message: 'The Route already has another domain change in progress.',
                    status: 409,
                );
            }

            $this->assertTargetsUnchanged($route, $expectedTargetIds);

            if (
                $replacement->publication !== RoutePublication::Public
                || $this->forwardRank($replacement->replacement_step)
                    >= $this->forwardRank(RouteReplacementStep::IngressFirewall)
            ) {
                return $this->cleanup(
                    $replacement,
                    $route,
                    $this->eligibleTargets($route, allowRetiring: true, allowGenerated: $allowGenerated),
                );
            }
        }

        $this->assertTargetsUnchanged($route, $expectedTargetIds);

        if ($route->domain === $domain && $route->replaced_by_route_id === null) {
            return $route;
        }

        $targets = $this->eligibleTargets(
            $route,
            allowRetiring: $route->status === RouteStatus::Retiring,
            publication: $publication ?? $route->publication,
            allowGenerated: $allowGenerated,
        );
        $replacement = $this->reserve($route, $domain, $publication);

        if (
            (
                $replacement->status === RouteStatus::Activating
                || $replacement->replacement_step === RouteReplacementStep::DatabaseCutover
            )
            && (
                $replacement->publication !== RoutePublication::Public
                || $this->forwardRank($replacement->replacement_step)
                    >= $this->forwardRank(RouteReplacementStep::IngressFirewall)
            )
        ) {
            return $this->cleanup($replacement, $route->refresh(), $targets);
        }

        $failureStep = 'workload-certificate';

        try {
            $this->forwardStep(
                $replacement,
                RouteReplacementStep::WorkloadCertificate,
                function () use ($targets, $route, $replacement): void {
                    foreach ($targets as $appInstance) {
                        $this->projection->prepareWorkloadCertificate($appInstance, $route, $replacement);
                    }
                },
            );
            $failureStep = 'workload-caddy';
            $this->forwardStep(
                $replacement,
                RouteReplacementStep::WorkloadCaddy,
                function () use ($targets, $route, $replacement): void {
                    foreach ($targets as $appInstance) {
                        $this->projection->prepareWorkloadCaddy($appInstance, $route, $replacement);
                    }
                },
            );
            $failureStep = 'router-certificate';
            $this->forwardStep(
                $replacement,
                RouteReplacementStep::RouterCertificate,
                function () use ($targets, $route, $replacement): void {
                    foreach ($targets as $appInstance) {
                        $this->projection->prepareRouterCertificate($appInstance, $route, $replacement);
                    }
                },
            );
            $failureStep = 'firewall-policy';
            $this->forwardStep(
                $replacement,
                RouteReplacementStep::FirewallPolicy,
                function () use ($targets, $replacement): void {
                    foreach ($targets as $appInstance) {
                        $this->projection->prepareFirewallPolicy($appInstance, $replacement);
                    }
                },
            );
            $failureStep = 'workload-verify';
            $this->forwardStep(
                $replacement,
                RouteReplacementStep::WorkloadVerified,
                function () use ($targets, $replacement): void {
                    foreach ($targets as $appInstance) {
                        $this->projection->verifyWorkload($appInstance, $replacement);
                    }
                },
            );
            $failureStep = 'router-caddy';
            $this->forwardStep(
                $replacement,
                RouteReplacementStep::RouterCaddy,
                function () use ($targets, $route, $replacement): void {
                    foreach ($targets as $appInstance) {
                        $this->projection->prepareRouterCaddy($appInstance, $route, $replacement);
                    }
                },
            );

            if ($replacement->publication === RoutePublication::Public) {
                $failureStep = 'ingress-certificate';
                $this->forwardStep(
                    $replacement,
                    RouteReplacementStep::IngressCertificate,
                    fn () => $this->projection->prepareIngressCertificate($replacement),
                );
                $failureStep = 'ingress-caddy';
                $this->forwardStep(
                    $replacement,
                    RouteReplacementStep::IngressCaddy,
                    fn () => $this->projection->stageIngressCaddy($replacement),
                );
                $failureStep = 'public-edge-verified';
                $this->forwardStep(
                    $replacement,
                    RouteReplacementStep::PublicEdgeVerified,
                    fn () => $this->projection->verifyPublicEdge($replacement),
                );
            }

            $production = array_values(array_filter(
                $targets,
                static fn (AppInstance $instance): bool => $instance->environment === 'production',
            ));

            if ($production !== []) {
                $failureStep = 'environment-synchronization';
                $this->forwardStep(
                    $replacement,
                    RouteReplacementStep::EnvironmentSynchronized,
                    function () use ($production): void {
                        foreach ($production as $appInstance) {
                            $this->routeEnvironment->synchronizeRouteDomain(
                                $appInstance,
                                AppInstanceEnvironmentRouteDomain::Candidate,
                            );
                        }
                    },
                );
            } else {
                $failureStep = 'laravel-url';
                $this->forwardStep(
                    $replacement,
                    RouteReplacementStep::LaravelUrl,
                    function () use ($targets, $replacement): void {
                        foreach ($targets as $appInstance) {
                            if ($appInstance->source_is_laravel) {
                                $this->configuration->configureLaravelUrl(
                                    $appInstance,
                                    "https://{$replacement->domain}",
                                );
                            }
                        }
                    },
                );
            }

            $failureStep = 'dns-publication';
            $this->forwardStep(
                $replacement,
                RouteReplacementStep::DnsPublished,
                fn () => $this->projection->publishDns($route, $replacement),
            );
            $failureStep = 'database-cutover';
            $this->cutover($route, $replacement);

            if ($replacement->publication === RoutePublication::Public) {
                $failureStep = 'public-activated';
                $this->forwardStep(
                    $replacement,
                    RouteReplacementStep::PublicActivated,
                    function () use ($replacement): void {
                        $replacement->update(['public_publication' => RoutePublicPublication::Active]);
                        $this->projection->activatePublicHandler($replacement);
                    },
                );
                $failureStep = 'ingress-firewall';
                $this->forwardStep(
                    $replacement,
                    RouteReplacementStep::IngressFirewall,
                    fn () => $this->projection->prepareIngressFirewall($replacement),
                );
            }
        } catch (Throwable $exception) {
            $this->recordFailure($replacement, $failureStep, $this->errorCode($exception));

            if (
                $replacement->refresh()->status === RouteStatus::Activating
                && $replacement->publication === RoutePublication::Public
                && $this->forwardRank($replacement->replacement_step)
                    < $this->forwardRank(RouteReplacementStep::PublicActivated)
            ) {
                $replacement->update(['public_publication' => RoutePublicPublication::Inactive]);
                $this->projection->rollbackPublicEdge($replacement);
            }

            $this->failBeforeCutover($replacement, $targets);

            throw $exception;
        }

        return $this->cleanup($replacement->refresh(), $route->refresh(), $targets);
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

    /** @return list<AppInstance> */
    private function eligibleTargets(
        Route $route,
        bool $allowRetiring = false,
        ?RoutePublication $publication = null,
        bool $allowGenerated = false,
    ): array {
        $targets = $route->targets
            ->map(static fn ($row) => $row->appInstance)
            ->filter(static fn ($instance): bool => $instance instanceof AppInstance)
            ->values()
            ->all();

        if ($targets === []) {
            app(RouteReconciliationGuard::class)->refuse();
        }

        $environments = array_values(array_unique(array_map(
            static fn (AppInstance $instance): string => $instance->environment,
            $targets,
        )));

        $statusAllowed = $route->status === RouteStatus::Active
            || ($allowRetiring && $route->status === RouteStatus::Retiring);

        $effectivePublication = $publication ?? $route->publication;
        $generatedTldChange = $allowGenerated
            && $route->provenance === RouteProvenance::Generated
            && $effectivePublication === RoutePublication::Private
            && count($targets) === 1;

        if (
            ! $statusAllowed
            || (
                ! $generatedTldChange
                && $route->provenance !== RouteProvenance::Explicit
            )
            || ($effectivePublication === RoutePublication::Public && $environments !== ['production'])
            || array_diff($environments, ['development', 'production']) !== []
            || (count($targets) > 1 && $environments !== ['production'])
        ) {
            app(RouteReconciliationGuard::class)->refuse();
        }

        foreach ($targets as $target) {
            if ($target->status !== AppInstanceState::Active) {
                app(RouteReconciliationGuard::class)->refuse();
            }

            if ($target->source_is_laravel === null) {
                new AppInstanceSourceProfileGuard()->refuseMissing();
            }
        }

        return $targets;
    }

    private function reserve(Route $route, string $domain, ?RoutePublication $publication = null): Route
    {
        /** @var Route $reserved */
        $reserved = DB::transaction(function () use ($route, $domain, $publication): Route {
            $locked = Route::query()->with('targets')->lockForUpdate()->findOrFail($route->id);

            if ($locked->replaced_by_route_id !== null) {
                $existing = Route::query()->lockForUpdate()->findOrFail($locked->replaced_by_route_id);

                if ($existing->domain !== $domain) {
                    throw new ResourceOperationException(
                        errorCode: 'route.domain_change_conflict',
                        message: 'The Route already has another domain change in progress.',
                        status: 409,
                    );
                }

                if (
                    $publication instanceof RoutePublication
                    && $existing->publication !== $publication
                ) {
                    throw new ResourceOperationException(
                        errorCode: 'route.domain_change_conflict',
                        message: 'The Route already has another domain change in progress.',
                        status: 409,
                    );
                }

                if ($existing->status === RouteStatus::Failed) {
                    $existing->update([
                        'status' => RouteStatus::Pending,
                        'failed_step' => null,
                        'error_code' => null,
                        'replacement_step' => $existing->replacement_step ?? RouteReplacementStep::Reserved,
                    ]);
                }

                return $existing->refresh()->load('targets');
            }

            $occupied = Route::query()
                ->whereKeyNot($locked->id)
                ->where('domain', $domain)
                ->exists();

            if ($occupied) {
                throw new ResourceOperationException(
                    errorCode: 'route.domain_conflict',
                    message: 'The Route domain is already owned.',
                    status: 409,
                );
            }

            $replacement = Route::query()->create([
                'app_id' => $locked->app_id,
                'node_id' => $locked->node_id,
                'cluster_id' => $locked->cluster_id,
                'generation_basis_node_id' => $locked->generation_basis_node_id,
                'domain' => $domain,
                'provenance' => $locked->provenance,
                'publication' => $publication ?? $locked->publication,
                'status' => RouteStatus::Pending,
                'replaces_route_id' => $locked->id,
                'replacement_step' => RouteReplacementStep::Reserved,
            ]);

            $locked->update(['replaced_by_route_id' => $replacement->id]);

            foreach ($locked->targets as $target) {
                $replacement->targets()->create([
                    'app_instance_id' => $target->app_instance_id,
                    'position' => $target->position,
                ]);
            }

            return $replacement->refresh()->load('targets');
        });

        return $reserved;
    }

    private function forwardStep(Route $route, RouteReplacementStep $step, callable $operation): void
    {
        if ($this->forwardRank($route->replacement_step) >= $this->forwardRank($step)) {
            return;
        }

        $operation();
        $this->checkpoint($route, $step);
    }

    private function cutover(Route $current, Route $replacement): void
    {
        DB::transaction(function () use ($current, $replacement): void {
            $lockedCurrent = Route::query()->lockForUpdate()->findOrFail($current->id);
            $lockedReplacement = Route::query()->lockForUpdate()->findOrFail($replacement->id);

            if (
                $lockedCurrent->replaced_by_route_id !== $lockedReplacement->id
                || $lockedReplacement->replaces_route_id !== $lockedCurrent->id
            ) {
                throw new ResourceOperationException(
                    errorCode: 'route.domain_change_conflict',
                    message: 'The Route domain change evidence changed during convergence.',
                    status: 409,
                );
            }

            $lockedCurrent->update(['status' => RouteStatus::Retiring]);
            $lockedReplacement->update([
                'status' => RouteStatus::Activating,
                'replacement_step' => RouteReplacementStep::DatabaseCutover,
                'failed_step' => null,
                'error_code' => null,
            ]);
            $current->setRawAttributes($lockedCurrent->refresh()->getAttributes(), true);
            $replacement->setRawAttributes($lockedReplacement->refresh()->getAttributes(), true);
        });
    }

    /** @param list<AppInstance> $targets */
    private function cleanup(Route $replacement, Route $current, array $targets): Route
    {
        try {
            foreach ($targets as $appInstance) {
                $this->projection->cleanup($appInstance, $current);

                if ($appInstance->environment === 'production') {
                    $this->routeEnvironment->synchronizeRouteDomain(
                        $appInstance,
                        AppInstanceEnvironmentRouteDomain::Candidate,
                    );
                } elseif ($appInstance->source_is_laravel) {
                    $this->configuration->configureLaravelUrl(
                        $appInstance,
                        "https://{$replacement->domain}",
                    );
                }

                $this->projection->verifyWorkload($appInstance, $replacement);
            }

            DB::transaction(function () use ($replacement, $current): void {
                $lockedCurrent = Route::query()->lockForUpdate()->findOrFail($current->id);
                $lockedReplacement = Route::query()->lockForUpdate()->findOrFail($replacement->id);
                $lockedCurrent->targets()->delete();
                $lockedCurrent->delete();
                $lockedReplacement->update([
                    'status' => RouteStatus::Active,
                    'replaces_route_id' => null,
                    'replacement_step' => null,
                    'failed_step' => null,
                    'error_code' => null,
                ]);
            });
        } catch (Throwable $exception) {
            $this->recordFailure($replacement, 'cleanup', $this->errorCode($exception));

            throw $exception;
        }

        return $replacement->refresh()->load('targets');
    }

    /** @param list<AppInstance> $targets */
    private function failBeforeCutover(Route $replacement, array $targets): void
    {
        $replacement->refresh();

        if ($replacement->status === RouteStatus::Activating) {
            return;
        }

        $old = Route::query()->find((int) $replacement->replaces_route_id);

        try {
            foreach ($targets as $appInstance) {
                $this->projection->rollbackCertificates($appInstance, $replacement);
                $this->projection->rollbackCaddy($appInstance, $replacement);

                if ($appInstance->environment === 'production') {
                    $this->routeEnvironment->synchronizeRouteDomain(
                        $appInstance,
                        AppInstanceEnvironmentRouteDomain::Authoritative,
                    );
                } elseif ($appInstance->source_is_laravel && $old instanceof Route) {
                    $this->configuration->configureLaravelUrl(
                        $appInstance,
                        "https://{$old->domain}",
                    );
                }
            }

            $this->projection->rollbackDns($replacement);

            if ($replacement->publication === RoutePublication::Public) {
                $this->projection->rollbackPublicEdge($replacement);
            }

            DB::transaction(function () use ($replacement): void {
                $locked = Route::query()->lockForUpdate()->findOrFail($replacement->id);
                $old = Route::query()->lockForUpdate()->findOrFail((int) $locked->replaces_route_id);
                $old->update(['replaced_by_route_id' => null]);
                $locked->targets()->delete();
                $locked->delete();
            });
        } catch (Throwable) {
            Route::query()
                ->whereKey($replacement->id)
                ->update(['status' => RouteStatus::Failed->value]);
        }
    }

    private function checkpoint(Route $route, RouteReplacementStep $step): void
    {
        DB::transaction(function () use ($route, $step): void {
            $locked = Route::query()->lockForUpdate()->findOrFail($route->id);
            $locked->update([
                'replacement_step' => $step,
                'failed_step' => null,
                'error_code' => null,
            ]);
            $route->setRawAttributes($locked->refresh()->getAttributes(), true);
        });
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
            : 'route.domain_change_failed';
    }

    private function forwardRank(?RouteReplacementStep $step): int
    {
        return match ($step) {
            RouteReplacementStep::Reserved => 0,
            RouteReplacementStep::WorkloadCertificate => 1,
            RouteReplacementStep::WorkloadCaddy => 2,
            RouteReplacementStep::RouterCertificate => 3,
            RouteReplacementStep::FirewallPolicy => 4,
            RouteReplacementStep::WorkloadVerified => 5,
            RouteReplacementStep::RouterCaddy => 6,
            RouteReplacementStep::IngressCertificate => 7,
            RouteReplacementStep::IngressCaddy => 8,
            RouteReplacementStep::PublicEdgeVerified => 9,
            RouteReplacementStep::LaravelUrl, RouteReplacementStep::EnvironmentSynchronized => 10,
            RouteReplacementStep::DnsPublished => 11,
            RouteReplacementStep::DatabaseCutover => 12,
            RouteReplacementStep::PublicActivated => 13,
            RouteReplacementStep::IngressFirewall => 14,
            RouteReplacementStep::Cleanup => 15,
            default => -1,
        };
    }
}
