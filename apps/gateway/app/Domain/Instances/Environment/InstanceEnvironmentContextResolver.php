<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Domain\Instances\InstanceSandboxGuard;
use App\Domain\Instances\InstanceSourceProfileGuard;
use App\Domain\Instances\InstanceState;
use App\Domain\Routes\PublicRouteEligibility;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Database\Eloquent\Builder;

final readonly class InstanceEnvironmentContextResolver
{
    public function resolve(
        Instance $instance,
        bool $requireActiveNode,
        bool $lockRoute = false,
    ): InstanceEnvironmentContext {
        InstanceSandboxGuard::assertHostOperation($instance);
        $sourceIsLaravel = $instance->source_is_laravel;
        $environment = $instance->placementEnvironment();

        if (
            $instance->status !== InstanceState::Active
            || $instance->provisioning_step !== 'active'
            || $environment === null
        ) {
            $this->conflict();
        }

        if (! is_bool($sourceIsLaravel)) {
            new InstanceSourceProfileGuard()->refuseMissing();
        }

        $node = Node::query()->findOrFail($instance->node_id);

        if ($requireActiveNode && $node->status !== LifecycleStatus::Active) {
            $this->conflict();
        }

        $routeQuery = Route::query()
            ->whereHas('targets', static fn (Builder $query): Builder => $query
                ->where('instance_id', $instance->id))
            ->orderBy('id')
            ->limit(2);

        if ($lockRoute) {
            $routeQuery->lockForUpdate();
        }

        $routes = $routeQuery->get();

        if ($routes->count() > 1) {
            $this->conflict();
        }

        $route = $routes->first();

        if ($route instanceof Route && (
            ! $route->isAuthoritative()
            || $route->replaced_by_route_id !== null
            || $route->replaces_route_id !== null
            || $route->failed_step !== null
            || $route->error_code !== null
            || ($route->replacement_step !== null && ! new PublicRouteEligibility()->publicEdgeIsLive($route))
        )) {
            $this->conflict();
        }

        [$path, $executionUser] = $this->placement($instance, $node);

        return new InstanceEnvironmentContext(
            instanceId: $instance->id,
            projectId: $instance->project_id,
            nodeId: $instance->node_id,
            environment: $environment,
            path: $path,
            executionUser: $executionUser,
            laravel: $sourceIsLaravel,
            routeId: $route?->id,
            routeDomain: $route?->domain,
            nodeStatus: $node->status->value,
            node: $node,
        );
    }

    public function resolveForClone(
        Instance $instance,
        bool $requireActiveNode,
        bool $lockRoute = false,
    ): InstanceEnvironmentContext {
        InstanceSandboxGuard::assertHostOperation($instance);
        $sourceIsLaravel = $instance->source_is_laravel;
        $environment = $instance->placementEnvironment();

        if (
            ! in_array(
                $instance->status,
                [InstanceState::Reserved, InstanceState::CheckoutPrepared, InstanceState::SourceResolved],
                strict: true,
            )
            || $environment !== 'production'
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
                ->where('instance_id', $instance->id))
            ->orderBy('id')
            ->limit(2);

        if ($lockRoute) {
            $routeQuery->lockForUpdate();
        }

        $routes = $routeQuery->get();

        if ($routes->count() > 1) {
            $this->conflict();
        }

        $route = $routes->first();

        if ($route instanceof Route && (
            $route->status !== RouteStatus::Pending
            || $route->domain !== $instance->clone_preview_domain
            || $route->replaced_by_route_id !== null
            || $route->replaces_route_id !== null
            || $route->replacement_step !== null
        )) {
            $this->conflict();
        }

        [$path, $executionUser] = $this->placement($instance, $node);

        return new InstanceEnvironmentContext(
            instanceId: $instance->id,
            projectId: $instance->project_id,
            nodeId: $instance->node_id,
            environment: $environment,
            path: $path,
            executionUser: $executionUser,
            laravel: $sourceIsLaravel,
            routeId: $route?->id,
            routeDomain: $route?->domain,
            nodeStatus: $node->status->value,
            node: $node,
        );
    }

    public function resolveForRouteTransition(
        Instance $instance,
        InstanceEnvironmentRouteDomain $domain,
        bool $requireActiveNode,
        bool $lockRoute = false,
    ): InstanceEnvironmentContext {
        InstanceSandboxGuard::assertHostOperation($instance);
        $sourceIsLaravel = $instance->source_is_laravel;
        $environment = $instance->placementEnvironment();

        if (
            $instance->status !== InstanceState::Active
            || ! in_array($environment, ['development', 'production'], true)
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
                ->where('instance_id', $instance->id))
            ->orderBy('id')
            ->limit(2);

        if ($lockRoute) {
            $routeQuery->lockForUpdate();
        }

        $routes = $routeQuery->get();

        if ($routes->count() < 1 || $routes->count() > 2) {
            $this->conflict();
        }

        $authoritative = $routes->first(
            static fn (Route $route): bool => $route->isAuthoritative(),
        );
        $candidate = $routes->first(
            static fn (Route $route): bool => $route->replaces_route_id !== null
                || $route->status === RouteStatus::Pending
                || $route->status === RouteStatus::Failed,
        ) ?? $authoritative;

        if (! $authoritative instanceof Route || ! $candidate instanceof Route) {
            $this->conflict();
        }

        $route = $domain === InstanceEnvironmentRouteDomain::Candidate ? $candidate : $authoritative;
        $routeDomain = $route->domain;

        if (
            $route->provenance !== $authoritative->provenance
            || $routeDomain === ''
        ) {
            $this->conflict();
        }

        [$path, $executionUser] = $this->placement($instance, $node);

        return new InstanceEnvironmentContext(
            instanceId: $instance->id,
            projectId: $instance->project_id,
            nodeId: $instance->node_id,
            environment: $environment,
            path: $path,
            executionUser: $executionUser,
            laravel: $sourceIsLaravel,
            routeId: $route->id,
            routeDomain: $routeDomain,
            nodeStatus: $node->status->value,
            node: $node,
            routeDomainSource: $domain,
        );
    }

    /** @return array{string, string} */
    private function placement(Instance $instance, Node $node): array
    {
        if ($instance->placementEnvironment() === 'development') {
            $path = $instance->source_is_laravel === true ? $instance->applicationDirectory() : $instance->getAttribute('checkout_path');
            $executionUser = $node->user;
        } else {
            $path = $instance->getAttribute('production_home');
            $executionUser = $instance->production_user;

            if (! $instance->usesProductionReleaseLayout()) {
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
            message: 'The Instance environment owner is not available for this operation.',
            status: 409,
        );
    }
}
