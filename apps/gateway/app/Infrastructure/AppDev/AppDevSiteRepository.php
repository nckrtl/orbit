<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteHostnameChangeDirection;
use App\Domain\Routes\RouteHostnameChangeStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\AppInstance;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Route;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** @mago-expect lint:cyclomatic-complexity,kan-defect One inventory composes node-scoped legacy, workload, and Router eligibility. */
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
        $instanceQuery = Instance::query()
            ->with(['node', 'workspaces'])
            ->whereIn('status', [LifecycleStatus::Provisioning->value, LifecycleStatus::Active->value]);

        if ($node instanceof Node) {
            $instanceQuery->where('node_id', $node->id);
        }

        $instances = $instanceQuery
            ->latest('id')
            ->get();
        /** @var Collection<int, Instance> $instances */
        /** @var Collection<int, AppDevSite> $sites */
        $sites = collect();

        foreach ($instances as $instance) {
            if (! is_string($instance->node->wireguard_ip)) {
                continue;
            }

            $sites->push($this->instanceSite($instance));

            foreach ($instance->workspaces as $workspace) {
                if (! in_array(
                    needle: $workspace->status,
                    haystack: [LifecycleStatus::Provisioning, LifecycleStatus::Active],
                    strict: true,
                )) {
                    continue;
                }

                $sites->push($this->workspaceSite($instance, $workspace));
            }
        }

        $routeQuery = Route::query()
            ->with(['targets.appInstance.app', 'targets.appInstance.node', 'cluster.routerAssignment.node'])
            ->where(static function (Builder $query) use ($pendingRoute): void {
                $query->where('status', RouteStatus::Active->value);

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
                assert($target instanceof AppInstance);

                $sites->push($this->appInstanceSite($target, $route));
            }

            if ($hasRouterSite) {
                assert($router instanceof Node);

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

            if (
                $route->hostname_change_direction === RouteHostnameChangeDirection::Forward
                && in_array(
                    $route->hostname_change_step,
                    [RouteHostnameChangeStep::LaravelUrl, RouteHostnameChangeStep::DnsPublished],
                    true,
                )
                && is_string($route->hostname_change_target)
                && ! ($additionalRoute instanceof Route
                && $additionalRoute->id === $route->id
                && $additionalRoute->hostname === $route->hostname_change_target)
            ) {
                $candidate = clone $route;
                $candidate->hostname = $route->hostname_change_target;
                $this->appendHostnameChangeSites($sites, $candidate);
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
            assert($target instanceof AppInstance);
            $sites->push($this->appInstanceSite($target, $route, hostnameChange: true));
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
                hostnameChange: true,
            ));
        }
    }

    private function instanceSite(Instance $instance): AppDevSite
    {
        return new AppDevSite(
            nodeId: $instance->node_id,
            nodeAddress: $instance->node->wireguard_ip ?? '',
            scope: "instance-{$instance->id}",
            checkoutPath: $instance->checkout_path,
            documentRoot: $instance->document_root,
            phpVersion: $instance->php_version,
            hostname: $instance->hostname,
        );
    }

    private function workspaceSite(Instance $instance, Workspace $workspace): AppDevSite
    {
        return new AppDevSite(
            nodeId: $instance->node_id,
            nodeAddress: $instance->node->wireguard_ip ?? '',
            scope: "workspace-{$workspace->id}",
            checkoutPath: $workspace->checkout_path,
            documentRoot: $instance->document_root,
            phpVersion: $workspace->php_version ?? $instance->php_version,
            hostname: $workspace->hostname,
        );
    }

    private function appInstanceSite(
        AppInstance $instance,
        Route $route,
        bool $hostnameChange = false,
    ): AppDevSite {
        return new AppDevSite(
            nodeId: $instance->node_id,
            nodeAddress: $instance->node->wireguard_ip ?? '',
            scope: "app-instance-{$instance->id}",
            checkoutPath: $instance->checkout_path,
            documentRoot: $instance->effectiveRoot() ?? '',
            phpVersion: $instance->selected_php_version,
            hostname: $route->hostname,
            environment: $instance->environment,
            appSlug: $instance->app->slug,
            certificateScope: $hostnameChange ? "app-instance-{$instance->id}-hostname-change" : null,
        );
    }

    /** @param list<AppInstance> $instances */
    private function routerSite(
        array $instances,
        Route $route,
        Node $router,
        bool $hostnameChange = false,
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
            hostname: $route->hostname,
            upstreamAddresses: $addresses,
            certificateScope: $hostnameChange ? "route-{$route->id}-router-hostname-change" : null,
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
            hostname: $route->hostname,
            unavailable: true,
        );
    }
}
