<?php

declare(strict_types=1);

namespace App\Infrastructure\Apps;

use App\Domain\AppInstances\DevelopmentAppInstanceConfigurator;
use App\Domain\AppInstances\DevelopmentRouteProjector;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentRouteDomain;
use App\Domain\AppInstances\Environment\AppInstanceRouteEnvironmentSynchronizer;
use App\Domain\AppInstances\ProductionPhpRuntimeManager;
use App\Domain\Apps\AppUpdateProjectionMutator;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStateResolver;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceEnvironmentValue;
use App\Models\Route;
use Throwable;

final readonly class NativeAppUpdateProjectionMutator implements AppUpdateProjectionMutator
{
    public function __construct(
        private RouteStateResolver $domains,
        private DevelopmentAppInstanceConfigurator $laravel,
        private AppInstanceRouteEnvironmentSynchronizer $environment,
        private DevelopmentRouteProjector $developmentRuntime,
        private ProductionPhpRuntimeManager $productionRuntime,
    ) {}

    public function preflightSlug(OrbitApp $app, string $newSlug): array
    {
        $routes = [];

        foreach ($app->routes()->with(['targets.appInstance.node', 'generationBasisNode'])->orderBy('id')->get() as $route) {
            if ($route->provenance !== RouteProvenance::Generated) {
                continue;
            }

            $target = $route->targets->first()?->appInstance;
            $proposed = $this->proposedDomain($app, $route, $target, $newSlug);
            $owner = Route::query()->where('domain', $proposed)->whereKeyNot($route->id)->first();

            if ($owner instanceof Route) {
                throw new ResourceOperationException(
                    errorCode: 'route.domain_conflict',
                    message: "Route domain [{$proposed}] would collide.",
                    status: 409,
                );
            }

            $routes[] = [
                'route_id' => $route->id,
                'previous_domain' => $route->domain,
                'proposed_domain' => $proposed,
                'instance_id' => $target?->id,
            ];
        }

        return ['routes' => $routes];
    }

    public function prepareSlug(OrbitApp $app, string $newSlug, array $inventory): array
    {
        $prepared = [];

        foreach ($inventory['routes'] ?? [] as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }

            $current = Route::query()->with('targets')->find((int) $proposal['route_id']);

            if (! $current instanceof Route) {
                continue;
            }

            $replacement = Route::query()->create([
                'app_id' => $current->app_id,
                'node_id' => $current->node_id,
                'cluster_id' => $current->cluster_id,
                'generation_basis_node_id' => $current->generation_basis_node_id,
                'domain' => $proposal['proposed_domain'],
                'provenance' => $current->provenance,
                'publication' => $current->publication,
                'status' => RouteStatus::Pending,
                'replaces_route_id' => $current->id,
                'replacement_step' => RouteReplacementStep::Reserved,
            ]);

            foreach ($current->targets as $target) {
                $replacement->targets()->create([
                    'app_instance_id' => $target->app_instance_id,
                    'position' => $target->position,
                ]);
            }

            $prepared[] = [
                ...$proposal,
                'replacement_id' => $replacement->id,
                'previous_env' => $this->storedUrl((int) ($proposal['instance_id'] ?? 0)),
            ];
        }

        return ['routes' => $prepared];
    }

    public function publishSlug(OrbitApp $app, string $newSlug, array $prepared): void
    {
        foreach ($prepared['routes'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $replacement = Route::query()->with('targets.appInstance')->find((int) $row['replacement_id']);
            $current = Route::query()->find((int) $row['route_id']);

            if ($replacement instanceof Route) {
                $replacement->update([
                    'status' => $current?->status ?? RouteStatus::Active,
                    'replacement_step' => null,
                ]);
            }

            if ($current instanceof Route) {
                $current->targets()->delete();
                $current->delete();
            }

            $instance = AppInstance::query()->find((int) ($row['instance_id'] ?? 0));

            if (! $instance instanceof AppInstance || ! $replacement instanceof Route) {
                continue;
            }

            try {
                $this->laravel->configureLaravelUrl($instance, 'https://'.$replacement->domain);
                $this->environment->synchronizeRouteDomain($instance, AppInstanceEnvironmentRouteDomain::Authoritative);
                $this->projectRuntime($instance, $replacement);
            } catch (Throwable) {
                continue;
            }
        }
    }

    public function rollbackSlug(OrbitApp $app, array $prepared): void
    {
        foreach ($prepared['routes'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $replacement = Route::query()->find((int) ($row['replacement_id'] ?? 0));

            if ($replacement instanceof Route) {
                $replacement->targets()->delete();
                $replacement->delete();
            }

            $instance = AppInstance::query()->find((int) ($row['instance_id'] ?? 0));
            $previous = $row['previous_env'] ?? null;

            if ($instance instanceof AppInstance && is_string($previous)) {
                $this->writeStoredUrl($instance->id, $previous);
            }
        }
    }

    public function preflightRoot(OrbitApp $app, string $newRoot): array
    {
        $instances = [];

        foreach ($app->appInstances as $instance) {
            if ($instance->root !== null) {
                continue;
            }

            $instances[] = [
                'instance_id' => $instance->id,
                'previous_root' => $app->root,
                'effective_root' => $this->effectiveRoot($instance, $newRoot),
            ];
        }

        return ['instances' => $instances];
    }

    public function prepareRoot(OrbitApp $app, string $newRoot, array $inventory): array
    {
        return $inventory;
    }

    public function publishRoot(OrbitApp $app, string $newRoot, array $prepared): void
    {
        foreach ($prepared['instances'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $instance = AppInstance::query()->find((int) $row['instance_id']);

            if (! $instance instanceof AppInstance) {
                continue;
            }

            $route = $instance->authoritativeRoute();

            if ($route instanceof Route) {
                $this->projectRuntime($instance, $route);
            }
        }
    }

    public function rollbackRoot(OrbitApp $app, array $prepared): void
    {
        $this->publishRoot($app, (string) $app->root, $prepared);
    }

    private function proposedDomain(OrbitApp $app, Route $route, ?AppInstance $instance, string $newSlug): string
    {
        $node = $instance?->node ?? $route->generationBasisNode;

        if ($node !== null && is_string($node->tld) && $node->tld !== '') {
            return $this->domains->generatedDomain($newSlug, $instance?->name ?? 'default', $node->tld);
        }

        if ($instance?->name === 'default' && str_starts_with($route->domain, $app->slug.'.')) {
            return $newSlug.substr($route->domain, strlen($app->slug));
        }

        return str_replace($app->slug, $newSlug, $route->domain);
    }

    private function storedUrl(int $instanceId): ?string
    {
        if ($instanceId < 1) {
            return null;
        }

        $value = AppInstanceEnvironmentValue::query()
            ->where('app_instance_id', $instanceId)
            ->where('env_key', 'APP_URL')
            ->first();

        return $value instanceof AppInstanceEnvironmentValue ? $value->env_value : null;
    }

    private function writeStoredUrl(int $instanceId, string $url): void
    {
        $value = AppInstanceEnvironmentValue::query()
            ->where('app_instance_id', $instanceId)
            ->where('env_key', 'APP_URL')
            ->first();

        if ($value instanceof AppInstanceEnvironmentValue) {
            $value->update(['env_value' => $url]);

            return;
        }

        AppInstanceEnvironmentValue::query()->create([
            'app_instance_id' => $instanceId,
            'env_key' => 'APP_URL',
            'env_value' => $url,
        ]);
    }

    private function projectRuntime(AppInstance $instance, Route $route): void
    {
        if ($instance->environment === 'production') {
            $this->productionRuntime->converge($instance);

            return;
        }

        $this->developmentRuntime->converge($instance, $route);
    }

    private function effectiveRoot(AppInstance $instance, ?string $appRoot): ?string
    {
        $previous = $instance->root;
        $instance->root = $instance->root ?? $appRoot;
        $effective = $instance->effectiveRoot();
        $instance->root = $previous;

        return $effective;
    }
}
