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
use App\Domain\Routes\RoutePlacement;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RoutePublicPublication;
use App\Domain\Routes\RouteReconciliationGuard;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use App\Models\Cluster;
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
        ?RoutePlacement $placement = null,
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
                    $placement,
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
        ?RoutePlacement $placement = null,
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
                    $placement,
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

        $placementChanged = $placement instanceof RoutePlacement
            && ($placement->nodeId !== $route->node_id || $placement->clusterId !== $route->cluster_id);
        $placementRetry = $placement instanceof RoutePlacement
            && $route->domain === $domain
            && $route->replaced_by_route_id === null
            && $this->forwardRank($route->replacement_step)
                >= $this->forwardRank(RouteReplacementStep::DatabaseCutover);

        if ($route->domain === $domain && $route->replaced_by_route_id === null && ! $placementChanged && ! $placementRetry) {
            return $route;
        }

        $targets = $this->eligibleTargets(
            $route,
            allowRetiring: $route->status === RouteStatus::Retiring,
            publication: $publication ?? $route->publication,
            allowGenerated: $allowGenerated,
        );

        if ($placement instanceof RoutePlacement && $route->domain === $domain && $route->replaced_by_route_id === null) {
            return $this->convergePlacement($route, $placement, $targets);
        }

        if (
            $route->replaced_by_route_id === null
            && $route->replacement_step !== null
            && $route->domain !== $domain
        ) {
            throw new ResourceOperationException(
                errorCode: 'route.domain_change_conflict',
                message: 'The Route already has another domain change in progress.',
                status: 409,
            );
        }

        $replacement = $this->reserve($route, $domain, $publication, $placement);

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

    private function reserve(
        Route $route,
        string $domain,
        ?RoutePublication $publication = null,
        ?RoutePlacement $placement = null,
    ): Route {
        /** @var Route $reserved */
        $reserved = DB::transaction(function () use ($route, $domain, $publication, $placement): Route {
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
                'node_id' => $placement instanceof RoutePlacement ? $placement->nodeId : $locked->node_id,
                'cluster_id' => $placement instanceof RoutePlacement ? $placement->clusterId : $locked->cluster_id,
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

            return $replacement->refresh()->load(['targets', 'cluster.routerAssignment.node']);
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

    /** @param list<AppInstance> $targets */
    private function convergePlacement(Route $route, RoutePlacement $placement, array $targets): Route
    {
        $retired = $this->candidateWithPlacement($route, new RoutePlacement(
            nodeId: $route->node_id,
            clusterId: $route->cluster_id,
            effectiveTld: null,
        ));
        $candidate = $this->candidateWithPlacement($route, $placement);

        if ($route->replacement_step === null) {
            $this->checkpoint($route, RouteReplacementStep::Reserved);
        }

        if (
            $route->node_id === $placement->nodeId
            && $route->cluster_id === $placement->clusterId
            && $this->forwardRank($route->replacement_step)
                >= $this->forwardRank(RouteReplacementStep::DatabaseCutover)
        ) {
            return $this->cleanupPlacement($route, $retired, $candidate, $targets);
        }

        $failureStep = 'workload-certificate';

        try {
            $this->forwardStep(
                $route,
                RouteReplacementStep::WorkloadCertificate,
                function () use ($targets, $route, $candidate): void {
                    foreach ($targets as $appInstance) {
                        $this->projection->prepareWorkloadCertificate($appInstance, $route, $candidate);
                    }
                },
            );
            $failureStep = 'workload-caddy';
            $this->forwardStep(
                $route,
                RouteReplacementStep::WorkloadCaddy,
                function () use ($targets, $route, $candidate): void {
                    foreach ($targets as $appInstance) {
                        $this->projection->prepareWorkloadCaddy($appInstance, $route, $candidate);
                    }
                },
            );
            $failureStep = 'router-certificate';
            $this->forwardStep(
                $route,
                RouteReplacementStep::RouterCertificate,
                function () use ($targets, $route, $candidate): void {
                    foreach ($targets as $appInstance) {
                        $this->projection->prepareRouterCertificate($appInstance, $route, $candidate);
                    }
                },
            );
            $failureStep = 'firewall-policy';
            $this->forwardStep(
                $route,
                RouteReplacementStep::FirewallPolicy,
                function () use ($targets, $candidate): void {
                    foreach ($targets as $appInstance) {
                        $this->projection->prepareFirewallPolicy($appInstance, $candidate);
                    }
                },
            );
            $failureStep = 'workload-verify';
            $this->forwardStep(
                $route,
                RouteReplacementStep::WorkloadVerified,
                function () use ($targets, $candidate): void {
                    foreach ($targets as $appInstance) {
                        $this->projection->verifyWorkload($appInstance, $candidate);
                    }
                },
            );
            $failureStep = 'router-caddy';
            $this->forwardStep(
                $route,
                RouteReplacementStep::RouterCaddy,
                function () use ($targets, $route, $candidate): void {
                    foreach ($targets as $appInstance) {
                        $this->projection->prepareRouterCaddy($appInstance, $route, $candidate);
                    }
                },
            );

            $production = array_values(array_filter(
                $targets,
                static fn (AppInstance $instance): bool => $instance->environment === 'production',
            ));

            if ($production !== []) {
                $failureStep = 'environment-synchronization';
                $this->forwardStep(
                    $route,
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
                    $route,
                    RouteReplacementStep::LaravelUrl,
                    function () use ($targets, $candidate): void {
                        foreach ($targets as $appInstance) {
                            if ($appInstance->source_is_laravel) {
                                $this->configuration->configureLaravelUrl(
                                    $appInstance,
                                    "https://{$candidate->domain}",
                                );
                            }
                        }
                    },
                );
            }

            $failureStep = 'dns-publication';
            $this->forwardStep(
                $route,
                RouteReplacementStep::DnsPublished,
                fn () => $this->projection->publishDns($route, $candidate),
            );
            $failureStep = 'database-cutover';
            $this->cutoverPlacement($route, $placement);
        } catch (Throwable $exception) {
            $this->recordFailure($route, $failureStep, $this->errorCode($exception));

            if (
                $this->forwardRank($route->refresh()->replacement_step)
                    < $this->forwardRank(RouteReplacementStep::DatabaseCutover)
            ) {
                $this->failBeforeCutoverPlacement($route, $retired, $targets);
            }

            throw $exception;
        }

        return $this->cleanupPlacement(
            $route->refresh(),
            $retired,
            $this->candidateWithPlacement($route->refresh(), $placement),
            $targets,
        );
    }

    private function candidateWithPlacement(Route $route, RoutePlacement $placement): Route
    {
        $candidate = $route->newInstance($route->getAttributes(), true);
        $candidate->exists = true;
        $candidate->node_id = $placement->nodeId;
        $candidate->cluster_id = $placement->clusterId;
        $candidate->setRelation('targets', $route->targets);

        if ($placement->clusterId === null) {
            $candidate->setRelation('cluster', null);

            return $candidate;
        }

        $candidate->setRelation(
            'cluster',
            Cluster::query()->with('routerAssignment.node')->find($placement->clusterId),
        );

        return $candidate;
    }

    private function cutoverPlacement(Route $route, RoutePlacement $placement): void
    {
        DB::transaction(function () use ($route, $placement): void {
            $locked = Route::query()->lockForUpdate()->findOrFail($route->id);
            $locked->update([
                'node_id' => $placement->nodeId,
                'cluster_id' => $placement->clusterId,
                'replacement_step' => RouteReplacementStep::DatabaseCutover,
                'failed_step' => null,
                'error_code' => null,
            ]);
            $route->setRawAttributes($locked->refresh()->getAttributes(), true);
        });
    }

    /** @param list<AppInstance> $targets */
    private function cleanupPlacement(Route $route, Route $retired, Route $candidate, array $targets): Route
    {
        try {
            foreach ($targets as $appInstance) {
                $this->projection->cleanup($appInstance, $retired);

                if ($appInstance->environment === 'production') {
                    $this->routeEnvironment->synchronizeRouteDomain(
                        $appInstance,
                        AppInstanceEnvironmentRouteDomain::Candidate,
                    );
                } elseif ($appInstance->source_is_laravel) {
                    $this->configuration->configureLaravelUrl(
                        $appInstance,
                        "https://{$candidate->domain}",
                    );
                }

                $this->projection->verifyWorkload($appInstance, $candidate);
            }

            DB::transaction(function () use ($route): void {
                $locked = Route::query()->lockForUpdate()->findOrFail($route->id);
                $locked->update([
                    'replacement_step' => null,
                    'failed_step' => null,
                    'error_code' => null,
                ]);
                $route->setRawAttributes($locked->refresh()->getAttributes(), true);
            });
        } catch (Throwable $exception) {
            $this->recordFailure($route, 'cleanup', $this->errorCode($exception));

            throw $exception;
        }

        return $route->refresh()->load('targets');
    }

    /** @param list<AppInstance> $targets */
    private function failBeforeCutoverPlacement(Route $route, Route $retired, array $targets): void
    {
        try {
            foreach ($targets as $appInstance) {
                $this->projection->rollbackCertificates($appInstance, $route);
                $this->projection->rollbackCaddy($appInstance, $route);

                if ($appInstance->environment === 'production') {
                    $this->routeEnvironment->synchronizeRouteDomain(
                        $appInstance,
                        AppInstanceEnvironmentRouteDomain::Authoritative,
                    );
                } elseif ($appInstance->source_is_laravel) {
                    $this->configuration->configureLaravelUrl(
                        $appInstance,
                        "https://{$retired->domain}",
                    );
                }
            }

            $this->projection->rollbackDns($route);
        } catch (Throwable) {
        }
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
                // The replacement is authoritative from cutover on, so the live certificate and the
                // staging scopes are named after it, not after the Route being retired.
                $this->projection->cleanup($appInstance, $replacement);

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
