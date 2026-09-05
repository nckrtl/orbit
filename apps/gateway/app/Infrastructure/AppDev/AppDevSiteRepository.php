<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\AppInstance;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Route;
use App\Models\Workspace;
use Illuminate\Support\Collection;

/** @mago-expect lint:cyclomatic-complexity One inventory composes legacy, AppInstance workload, and Router site eligibility. */
final readonly class AppDevSiteRepository
{
    /** @return Collection<int, AppDevSite> */
    public function forNode(Node $node, ?Route $pendingRoute = null): Collection
    {
        return $this->all($pendingRoute)->where('nodeId', $node->id)->values();
    }

    /** @return Collection<int, AppDevSite> */
    public function all(?Route $pendingRoute = null): Collection
    {
        $instances = Instance::query()
            ->with(['node', 'workspaces'])
            ->whereIn('status', [LifecycleStatus::Provisioning->value, LifecycleStatus::Active->value])
            ->latest('id')
            ->get();
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

        $routes = Route::query()
            ->with(['targets.appInstance.app', 'targets.appInstance.node', 'cluster.routerAssignment.node'])
            ->where(static function ($query) use ($pendingRoute): void {
                $query->where('status', RouteStatus::Active->value);

                if ($pendingRoute instanceof Route) {
                    $query->orWhere('id', $pendingRoute->id);
                }
            })
            ->get();

        foreach ($routes as $route) {
            $target = $route->targets->first()?->appInstance;

            if (! $target instanceof AppInstance || ! is_string($target->node->wireguard_ip)) {
                continue;
            }

            if (! in_array($target->status, [AppInstanceState::SourceResolved, AppInstanceState::Active], true)) {
                continue;
            }

            $sites->push($this->appInstanceSite($target, $route));

            $router = $route->cluster?->routerAssignment?->node;

            if (
                $router instanceof Node
                && is_string($router->wireguard_ip)
                && ! $router->is($target->node)
            ) {
                $sites->push($this->routerSite($target, $route, $router));
            }
        }

        /** @var Collection<int, AppDevSite> $sites */
        return $sites->values();
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

    private function appInstanceSite(AppInstance $instance, Route $route): AppDevSite
    {
        return new AppDevSite(
            nodeId: $instance->node_id,
            nodeAddress: $instance->node->wireguard_ip ?? '',
            scope: "app-instance-{$instance->id}",
            checkoutPath: $instance->checkout_path,
            documentRoot: $instance->effectiveRoot() ?? '',
            phpVersion: $instance->selected_php_version,
            hostname: $route->hostname,
        );
    }

    private function routerSite(AppInstance $instance, Route $route, Node $router): AppDevSite
    {
        $address = is_string($instance->node->lan_ip) && $instance->node->lan_ip !== ''
            ? $instance->node->lan_ip
            : $instance->node->wireguard_ip;

        return new AppDevSite(
            nodeId: $router->id,
            nodeAddress: $router->wireguard_ip ?? '',
            scope: "route-{$route->id}-router",
            checkoutPath: '',
            documentRoot: '',
            phpVersion: null,
            hostname: $route->hostname,
            upstreamAddress: $address,
        );
    }
}
