<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Environment;

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Routes\RouteHostnameChangeDirection;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Database\Eloquent\Builder;

final readonly class AppInstanceEnvironmentContextResolver
{
    public function resolve(
        AppInstance $instance,
        bool $requireActiveNode,
        bool $lockRoute = false,
    ): AppInstanceEnvironmentContext {
        $sourceIsLaravel = $instance->source_is_laravel;

        if (
            $instance->status !== AppInstanceState::Active
            || $instance->migration_required
            || $instance->provisioning_step !== 'active'
            || ! is_bool($sourceIsLaravel)
            || ! in_array($instance->environment, ['development', 'production'], true)
        ) {
            $this->conflict();
        }

        $node = Node::query()->findOrFail($instance->node_id);

        if ($requireActiveNode && $node->status !== LifecycleStatus::Active) {
            $this->conflict();
        }

        $routeQuery = Route::query()
            ->whereHas('targets', static fn (Builder $query): Builder => $query
                ->where('app_instance_id', $instance->id))
            ->orderBy('id')
            ->limit(2);

        if ($lockRoute) {
            $routeQuery->lockForUpdate();
        }

        $routes = $routeQuery->get();

        if ($routes->count() !== 1) {
            $this->conflict();
        }

        $route = $routes->sole();

        if (
            $route->status !== RouteStatus::Active
            || $route->hostname_change_target !== null
            || $route->hostname_change_direction !== null
            || $route->hostname_change_step !== null
        ) {
            $this->conflict();
        }

        [$path, $executionUser] = $this->placement($instance, $node);

        return new AppInstanceEnvironmentContext(
            appInstanceId: $instance->id,
            appId: $instance->app_id,
            nodeId: $instance->node_id,
            environment: $instance->environment,
            path: $path,
            executionUser: $executionUser,
            laravel: $sourceIsLaravel,
            routeId: $route->id,
            routeHostname: $route->hostname,
            nodeStatus: $node->status->value,
            node: $node,
        );
    }

    public function resolveForClone(
        AppInstance $instance,
        bool $requireActiveNode,
        bool $lockRoute = false,
    ): AppInstanceEnvironmentContext {
        $sourceIsLaravel = $instance->source_is_laravel;

        if (
            ! in_array(
                $instance->status,
                [AppInstanceState::Reserved, AppInstanceState::CheckoutPrepared, AppInstanceState::SourceResolved],
                strict: true,
            )
            || $instance->environment !== 'production'
            || $instance->migration_required
            || ! is_bool($sourceIsLaravel)
            || ! is_string($instance->provisioning_step)
            || ! str_starts_with($instance->provisioning_step, 'clone-')
            || ! is_int($instance->clone_candidate_id)
        ) {
            $this->conflict();
        }

        $node = Node::query()->findOrFail($instance->node_id);

        if ($requireActiveNode && $node->status !== LifecycleStatus::Active) {
            $this->conflict();
        }

        $routeQuery = Route::query()
            ->whereHas('targets', static fn (Builder $query): Builder => $query
                ->where('app_instance_id', $instance->id))
            ->orderBy('id')
            ->limit(2);

        if ($lockRoute) {
            $routeQuery->lockForUpdate();
        }

        $routes = $routeQuery->get();

        if ($routes->count() !== 1) {
            $this->conflict();
        }

        $route = $routes->sole();

        if (
            $route->status !== RouteStatus::Pending
            || $route->hostname !== $instance->clone_preview_hostname
            || $route->hostname_change_target !== null
            || $route->hostname_change_direction !== null
            || $route->hostname_change_step !== null
        ) {
            $this->conflict();
        }

        [$path, $executionUser] = $this->placement($instance, $node);

        return new AppInstanceEnvironmentContext(
            appInstanceId: $instance->id,
            appId: $instance->app_id,
            nodeId: $instance->node_id,
            environment: $instance->environment,
            path: $path,
            executionUser: $executionUser,
            laravel: $sourceIsLaravel,
            routeId: $route->id,
            routeHostname: $route->hostname,
            nodeStatus: $node->status->value,
            node: $node,
        );
    }

    public function resolveForRouteTransition(
        AppInstance $instance,
        AppInstanceEnvironmentRouteHostname $hostname,
        bool $requireActiveNode,
        bool $lockRoute = false,
    ): AppInstanceEnvironmentContext {
        $sourceIsLaravel = $instance->source_is_laravel;

        if (
            $instance->status !== AppInstanceState::Active
            || $instance->environment !== 'production'
            || $instance->migration_required
            || $instance->provisioning_step !== 'active'
            || ! is_bool($sourceIsLaravel)
        ) {
            $this->conflict();
        }

        $node = Node::query()->findOrFail($instance->node_id);

        if ($requireActiveNode && $node->status !== LifecycleStatus::Active) {
            $this->conflict();
        }

        $routeQuery = Route::query()
            ->whereHas('targets', static fn (Builder $query): Builder => $query
                ->where('app_instance_id', $instance->id))
            ->orderBy('id')
            ->limit(2);

        if ($lockRoute) {
            $routeQuery->lockForUpdate();
        }

        $routes = $routeQuery->get();

        if ($routes->count() !== 1) {
            $this->conflict();
        }

        $route = $routes->sole();
        $direction = $hostname === AppInstanceEnvironmentRouteHostname::Candidate
            ? RouteHostnameChangeDirection::Forward
            : RouteHostnameChangeDirection::Rollback;
        $routeHostname = $hostname === AppInstanceEnvironmentRouteHostname::Candidate
            ? $route->hostname_change_target
            : $route->hostname_change_previous;

        if (
            $route->status !== RouteStatus::Active
            || $route->provenance !== RouteProvenance::Explicit
            || $route->publication !== RoutePublication::Private
            || $route->hostname_change_direction !== $direction
            || $route->hostname_change_step === null
            || ! is_string($route->hostname_change_previous)
            || ! is_string($route->hostname_change_target)
            || ! is_string($routeHostname)
            || $routeHostname === ''
            || $route->targets()->count() !== 1
        ) {
            $this->conflict();
        }

        [$path, $executionUser] = $this->placement($instance, $node);

        return new AppInstanceEnvironmentContext(
            appInstanceId: $instance->id,
            appId: $instance->app_id,
            nodeId: $instance->node_id,
            environment: $instance->environment,
            path: $path,
            executionUser: $executionUser,
            laravel: $sourceIsLaravel,
            routeId: $route->id,
            routeHostname: $routeHostname,
            nodeStatus: $node->status->value,
            node: $node,
            routeHostnameSource: $hostname,
        );
    }

    /** @return array{string, string} */
    private function placement(AppInstance $instance, Node $node): array
    {
        if ($instance->environment === 'development') {
            $path = $instance->getAttribute('checkout_path');
            $executionUser = $node->user;
        } else {
            $path = $instance->getAttribute('production_home');
            $executionUser = $instance->production_user;

            if ($instance->checkout_path !== $path && ! $instance->usesProductionReleaseLayout()) {
                $this->conflict();
            }
        }

        if (! is_string($path) || ! $this->safeAbsolutePath($path)) {
            $this->conflict();
        }

        if (! is_string($executionUser) || preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/D', $executionUser) !== 1) {
            $this->conflict();
        }

        return [$path, $executionUser];
    }

    private function safeAbsolutePath(string $path): bool
    {
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\n") || str_contains($path, "\r")) {
            return false;
        }

        $segments = array_slice(explode('/', $path), 1);

        return
            $segments !== []
            && array_all($segments, static fn (string $segment): bool => ! in_array($segment, ['', '.', '..'], true));
    }

    private function conflict(): never
    {
        throw new ResourceOperationException(
            errorCode: 'env.owner_unavailable',
            message: 'The AppInstance environment owner is not available for this operation.',
            status: 409,
        );
    }
}
