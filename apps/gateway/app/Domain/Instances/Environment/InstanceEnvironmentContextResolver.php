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
use App\Models\InstanceAppProjection;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Database\Eloquent\Collection;

final readonly class InstanceEnvironmentContextResolver
{
    public function resolve(Instance $instance, bool $requireActiveNode, bool $lockRoute = false, ?string $app = null): InstanceEnvironmentContext
    {
        InstanceSandboxGuard::assertHostOperation($instance);
        InstanceAppProjection::assertAvailable([$instance->id]);

        return $this->publishedContext($instance, $requireActiveNode, $lockRoute, $app);
    }

    /** Only the recorded owner may inspect its still-published environment configuration. */
    public function resolveForProjection(Instance $instance, string $projectionId, string $app): InstanceEnvironmentContext
    {
        $projection = InstanceAppProjection::query()->find($projectionId);
        if (! $projection instanceof InstanceAppProjection || $projection->active_instance_id !== $instance->id
            || $projection->node_id !== $instance->node_id || $projection->completion !== null) {
            $this->conflict();
        }

        return $this->publishedContext($instance, true, true, $app);
    }

    private function publishedContext(Instance $instance, bool $requireActiveNode, bool $lockRoute, ?string $app): InstanceEnvironmentContext
    {
        $app = $instance->appConfiguration($app)['name'];
        if ($instance->status !== InstanceState::Active || $instance->provisioning_step !== 'active' || $instance->placementEnvironment() === null) {
            $this->conflict();
        }
        if (! is_bool($instance->runtimeForApp($app)['laravel'])) {
            new InstanceSourceProfileGuard()->refuseMissing();
        }
        $routes = $this->routes($instance, $app, $lockRoute);
        if ($routes->count() > 1) {
            $this->conflict();
        }
        $route = $routes->first();
        if ($route instanceof Route && (! $route->isAuthoritative() || $route->replaced_by_route_id !== null || $route->replaces_route_id !== null || $route->failed_step !== null || $route->error_code !== null || ($route->replacement_step !== null && ! new PublicRouteEligibility()->publicEdgeIsLive($route)))) {
            $this->conflict();
        }

        return $this->context($instance, $app, $route, $requireActiveNode);
    }

    public function resolveForClone(Instance $instance, bool $requireActiveNode, bool $lockRoute = false): InstanceEnvironmentContext
    {
        InstanceSandboxGuard::assertHostOperation($instance);
        $app = $instance->appConfiguration()['name'];
        if (! in_array($instance->status, [InstanceState::Reserved, InstanceState::CheckoutPrepared, InstanceState::SourceResolved], true) || $instance->placementEnvironment() !== 'production' || ! is_bool($instance->source_is_laravel) || ! is_string($instance->provisioning_step) || ! str_starts_with($instance->provisioning_step, 'clone-') || ! is_int($instance->clone_candidate_id)) {
            $this->conflict();
        }
        $routes = $this->routes($instance, $app, $lockRoute);
        if ($routes->count() > 1) {
            $this->conflict();
        }
        $route = $routes->first();
        if ($route instanceof Route && ($route->status !== RouteStatus::Pending || $route->domain !== $instance->clone_preview_domain || $route->replaced_by_route_id !== null || $route->replaces_route_id !== null || $route->replacement_step !== null)) {
            $this->conflict();
        }

        return $this->context($instance, $app, $route, $requireActiveNode);
    }

    public function resolveForRouteTransition(Instance $instance, InstanceEnvironmentRouteDomain $domain, bool $requireActiveNode, bool $lockRoute = false, ?string $app = null): InstanceEnvironmentContext
    {
        InstanceSandboxGuard::assertHostOperation($instance);
        InstanceAppProjection::assertAvailable([$instance->id]);
        $app = $instance->appConfiguration($app)['name'];
        if ($instance->status !== InstanceState::Active || ! in_array($instance->placementEnvironment(), ['development', 'production'], true) || $instance->provisioning_step !== 'active' || ! is_bool($instance->runtimeForApp($app)['laravel'])) {
            $this->conflict();
        }
        $routes = $this->routes($instance, $app, $lockRoute);
        if ($routes->count() < 1 || $routes->count() > 2) {
            $this->conflict();
        }
        $authoritative = $routes->first(static fn (Route $route): bool => $route->isAuthoritative());
        $candidate = $routes->first(static fn (Route $route): bool => $route->replaces_route_id !== null || in_array($route->status, [RouteStatus::Pending, RouteStatus::Failed], true)) ?? $authoritative;
        if (! $authoritative instanceof Route || ! $candidate instanceof Route || $candidate->provenance !== $authoritative->provenance) {
            $this->conflict();
        }
        $route = $domain === InstanceEnvironmentRouteDomain::Candidate ? $candidate : $authoritative;
        if ($route->domain === '') {
            $this->conflict();
        }

        return $this->context($instance, $app, $route, $requireActiveNode, $domain);
    }

    /** @return Collection<int, Route> */
    private function routes(Instance $instance, string $app, bool $lock): Collection
    {
        $query = Route::query()->where('app', $app)->whereHas('targets', static fn ($query) => $query->where('instance_id', $instance->id))->orderBy('id')->limit(3);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    private function context(Instance $instance, string $app, ?Route $route, bool $requireActiveNode, ?InstanceEnvironmentRouteDomain $source = null): InstanceEnvironmentContext
    {
        $node = Node::query()->findOrFail($instance->node_id);
        if ($requireActiveNode && $node->status !== LifecycleStatus::Active) {
            $this->conflict();
        }
        $environment = $instance->placementEnvironment();
        $laravel = $instance->runtimeForApp($app)['laravel'];
        if ($environment === null || ! is_bool($laravel)) {
            $this->conflict();
        }
        if ($environment === 'development') {
            $path = $instance->applicationDirectory($app);
            $user = $node->user;
        } else {
            $path = $instance->production_home;
            $user = $instance->production_user;
            if (! $instance->usesProductionReleaseLayout()) {
                $this->conflict();
            }
        }
        if (! is_string($path) || ! $this->safeAbsolutePath($path) || ! is_string($user) || preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/D', $user) !== 1) {
            $this->conflict();
        }

        return new InstanceEnvironmentContext(
            instanceId: $instance->id, projectId: $instance->project_id, nodeId: $instance->node_id,
            environment: $environment, path: $path, executionUser: $user, laravel: $laravel,
            routeId: $route?->id, routeDomain: $route?->domain, nodeStatus: $node->status->value,
            node: $node, routeDomainSource: $source, app: $app,
        );
    }

    private function safeAbsolutePath(string $path): bool
    {
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\n") || str_contains($path, "\r")) {
            return false;
        }
        $segments = array_slice(explode('/', $path), 1);

        return $segments !== [] && array_all($segments, static fn (string $segment): bool => ! in_array($segment, ['', '.', '..'], true));
    }

    private function conflict(): never
    {
        throw new ResourceOperationException('env.owner_unavailable', 'The Instance environment owner is not available for this operation.', 409);
    }
}
