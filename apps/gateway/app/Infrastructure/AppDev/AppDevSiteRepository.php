<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Routes\RouteStatus;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final readonly class AppDevSiteRepository
{
    /** @return Collection<int, AppDevSite> */
    public function forNode(
        Node $node,
        ?Route $pendingRoute = null,
        ?AppInstance $unavailableInstance = null,
        ?Route $additionalRoute = null,
    ): Collection {
        return $this->sites($node, $pendingRoute, $unavailableInstance, $additionalRoute);
    }

    /** @return Collection<int, AppDevSite> */
    public function all(
        ?Route $pendingRoute = null,
        ?AppInstance $unavailableInstance = null,
        ?Route $additionalRoute = null,
    ): Collection {
        return $this->sites(null, $pendingRoute, $unavailableInstance, $additionalRoute);
    }

    /** @return Collection<int, AppDevSite> */
    private function sites(
        ?Node $node,
        ?Route $pendingRoute,
        ?AppInstance $unavailableInstance,
        ?Route $additionalRoute,
    ): Collection {
        /** @var Collection<int, AppDevSite> $sites */
        $sites = collect();

        $routeQuery = Route::query()
            ->with(['targets.appInstance.app', 'targets.appInstance.node', 'cluster.routerAssignment.node'])
            ->where(static function (Builder $query) use ($pendingRoute): void {
                $query->whereIn('status', [
                    RouteStatus::Active->value,
                    RouteStatus::Activating->value,
                    RouteStatus::Retiring->value,
                    RouteStatus::Pending->value,
                    RouteStatus::Failed->value,
                ]);

                if ($pendingRoute instanceof Route) {
                    $query->orWhere('id', $pendingRoute->id);
                }
            });

        if ($node instanceof Node) {
            $nodeId = $node->id;
            $routeQuery->where(static function (Builder $query) use (
                $nodeId,
                $pendingRoute,
                $unavailableInstance,
            ): void {
                $query->where(static function (Builder $query) use (
                    $nodeId,
                    $pendingRoute,
                    $unavailableInstance,
                ): void {
                    $query
                        ->whereHas(
                            'targets.appInstance',
                            static fn (Builder $query): Builder => $query->where('node_id', $nodeId),
                        )
                        ->orWhereHas(
                            'cluster.routerAssignment',
                            static fn (Builder $query): Builder => $query->where('node_id', $nodeId),
                        );

                    if (
                        $pendingRoute instanceof Route
                        && $unavailableInstance instanceof AppInstance
                        && $unavailableInstance->node_id === $nodeId
                    ) {
                        $query->orWhere('routes.id', $pendingRoute->id);
                    }
                });
            });
        }

        $routes = $routeQuery->get();
        /** @var Collection<int, Route> $routes */
        foreach ($routes as $route) {
            $targets = $route
                ->targets
                ->map(static fn ($targetRow) => $targetRow->appInstance)
                ->filter(
                    static fn ($target): bool => (
                        $target instanceof AppInstance
                        && is_string($target->node->wireguard_ip)
                        && in_array(
                            $target->status,
                            [AppInstanceState::SourceResolved, AppInstanceState::Active],
                            true,
                        )
                    ),
                )
                ->values();
            $router = $route->cluster?->routerAssignment?->node;
            $hasRouterSite = $router instanceof Node
            && is_string($router->wireguard_ip)
            && $targets->isNotEmpty()
            && ! $targets->contains(
                static fn (AppInstance $target): bool => $router->is($target->node),
            );

            foreach ($targets as $target) {

                $sites->push($this->appInstanceSite($target, $route));
            }

            if ($hasRouterSite) {

                $sites->push($this->routerSite(array_values($targets->all()), $route, $router));
            }

            if (
                $pendingRoute instanceof Route
                && $route->is($pendingRoute)
                && $unavailableInstance instanceof AppInstance
                && $targets->isEmpty()
            ) {
                $sites->push($this->unavailableSite($unavailableInstance, $route, $router));
            }

        }

        if ($additionalRoute instanceof Route) {
            $this->appendHostnameChangeSites($sites, $additionalRoute);
        }

        if ($node instanceof Node) {
            return $sites->where('nodeId', $node->id)->values();
        }

        return $sites->values();
    }

    /** @param Collection<int, AppDevSite> $sites */
    private function appendHostnameChangeSites(Collection $sites, Route $route): void
    {
        $route->loadMissing([
            'targets.appInstance.app',
            'targets.appInstance.node',
            'cluster.routerAssignment.node',
        ]);
        $targets = $route
            ->targets
            ->map(static fn ($targetRow) => $targetRow->appInstance)
            ->filter(static fn ($target): bool => $target instanceof AppInstance)
            ->values();
        $router = $route->cluster?->routerAssignment?->node;

        foreach ($targets as $target) {
            $sites->push($this->appInstanceSite($target, $route, domainChange: true));
        }

        if (
            $router instanceof Node
            && $targets->isNotEmpty()
            && ! $targets->contains(static fn (AppInstance $target): bool => $router->is($target->node))
        ) {
            $sites->push($this->routerSite(
                array_values($targets->all()),
                $route,
                $router,
                domainChange: true,
            ));
        }
    }

    private function appInstanceSite(
        AppInstance $instance,
        Route $route,
        bool $domainChange = false,
    ): AppDevSite {
        $checkoutPath = $instance->usesProductionReleaseLayout()
            ? "{$instance->production_home}/current"
            : $instance->checkout_path;

        return new AppDevSite(
            nodeId: $instance->node_id,
            nodeAddress: $instance->node->wireguard_ip ?? '',
            scope: "app-instance-{$instance->id}",
            checkoutPath: $checkoutPath,
            documentRoot: $instance->root ?? $instance->app->root ?? '',
            phpVersion: $instance->selected_php_version,
            domain: $route->domain,
            environment: $instance->environment,
            productionUser: $instance->production_user,
            productionHome: $instance->production_home,
            appSlug: $instance->app->slug,
            certificateScope: $domainChange ? "app-instance-{$instance->id}-hostname-change" : null,
            productionPhpSocket: $instance->production_php_socket,
        );
    }

    /** @param list<AppInstance> $instances */
    private function routerSite(
        array $instances,
        Route $route,
        Node $router,
        bool $domainChange = false,
    ): AppDevSite {
        $addresses = collect($instances)
            ->map(static fn (AppInstance $instance): ?string => is_string($instance->node->lan_ip)
                && $instance->node->lan_ip !== ''
                    ? $instance->node->lan_ip
                    : $instance->node->wireguard_ip)
            ->filter(static fn (?string $address): bool => is_string($address) && $address !== '')
            ->values()
            ->all();

        /** @var list<string> $addresses */

        return new AppDevSite(
            nodeId: $router->id,
            nodeAddress: $router->wireguard_ip ?? '',
            scope: "route-{$route->id}-router",
            checkoutPath: '',
            documentRoot: '',
            phpVersion: null,
            domain: $route->domain,
            upstreamAddresses: $addresses,
            certificateScope: $domainChange ? "route-{$route->id}-router-hostname-change" : null,
        );
    }

    private function unavailableSite(AppInstance $instance, Route $route, ?Node $router): AppDevSite
    {
        $usesRouterProjection = $router instanceof Node && ! $router->is($instance->node);
        $node = $usesRouterProjection ? $router : $instance->node;
        $scope = $usesRouterProjection
            ? "route-{$route->id}-router"
            : "app-instance-{$instance->id}";

        return new AppDevSite(
            nodeId: $node->id,
            nodeAddress: $node->wireguard_ip ?? '',
            scope: $scope,
            checkoutPath: '',
            documentRoot: '',
            phpVersion: null,
            domain: $route->domain,
            unavailable: true,
        );
    }
}
