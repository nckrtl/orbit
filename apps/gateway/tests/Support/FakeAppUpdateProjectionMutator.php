<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Apps\AppUpdateProjectionMutator;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceEnvironmentValue;
use App\Models\Route;

final class FakeAppUpdateProjectionMutator implements AppUpdateProjectionMutator
{
    /** @var list<array{instance_id: int, url: string}> */
    public array $laravelUrls = [];

    /** @var list<array{instance_id: int, root: ?string, validated: bool, preserved_tuning: bool}> */
    public array $runtimeProjections = [];

    /** @var array<int, string> */
    public array $localTuning = [];

    public bool $refuseSlug = false;

    public bool $failSlugPrepare = false;

    public bool $applicationErrorOnUrl = false;

    public function preflightSlug(OrbitApp $app, string $newSlug): array
    {
        if ($this->refuseSlug) {
            throw new ResourceOperationException(
                errorCode: 'route.domain_conflict',
                message: 'A generated Route domain would collide.',
                status: 409,
            );
        }

        $routes = [];

        foreach ($app->routes()->with('targets.appInstance')->orderBy('id')->get() as $route) {
            if ($route->provenance !== RouteProvenance::Generated) {
                continue;
            }

            $target = $route->targets->first()?->appInstance;
            $routes[] = [
                'route_id' => $route->id,
                'previous_domain' => $route->domain,
                'proposed_domain' => $this->proposedDomain($app, $route, $target, $newSlug),
                'instance_id' => $target?->id,
            ];
        }

        foreach ($routes as $proposal) {
            $owner = Route::query()
                ->where('domain', $proposal['proposed_domain'])
                ->whereKeyNot($proposal['route_id'])
                ->first();

            if ($owner instanceof Route) {
                throw new ResourceOperationException(
                    errorCode: 'route.domain_conflict',
                    message: "Route domain [{$proposal['proposed_domain']}] would collide.",
                    status: 409,
                );
            }
        }

        return ['routes' => $routes];
    }

    public function prepareSlug(OrbitApp $app, string $newSlug, array $inventory): array
    {
        if ($this->failSlugPrepare) {
            throw new ResourceOperationException(
                errorCode: 'app.slug_prepare_failed',
                message: 'Preparing generated Route replacements failed.',
                status: 409,
            );
        }

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

            $env = $this->captureEnvironment((int) ($proposal['instance_id'] ?? 0));
            $prepared[] = [
                ...$proposal,
                'replacement_id' => $replacement->id,
                'previous_env' => $env,
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

            $replacement = Route::query()->find((int) $row['replacement_id']);
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

            $instanceId = (int) ($row['instance_id'] ?? 0);

            if ($instanceId < 1) {
                continue;
            }

            $url = 'https://'.$row['proposed_domain'];
            $this->laravelUrls[] = ['instance_id' => $instanceId, 'url' => $url];
            $this->writeEnvironment($instanceId, $url);

            if ($this->applicationErrorOnUrl) {
                continue;
            }

            $this->projectRuntime($instanceId);
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

            $instanceId = (int) ($row['instance_id'] ?? 0);
            $previous = $row['previous_env'] ?? null;

            if ($instanceId > 0 && is_string($previous)) {
                $this->writeEnvironment($instanceId, $previous);
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

            $this->projectRuntime((int) $row['instance_id'], $row['effective_root'] ?? null);
        }
    }

    public function rollbackRoot(OrbitApp $app, array $prepared): void
    {
        foreach ($prepared['instances'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $instance = AppInstance::query()->find((int) $row['instance_id']);

            if ($instance instanceof AppInstance) {
                $this->projectRuntime($instance->id, $this->effectiveRoot($instance, $app->root));
            }
        }
    }

    private function proposedDomain(OrbitApp $app, Route $route, ?AppInstance $instance, string $newSlug): string
    {
        $name = $instance?->name ?? 'default';

        if ($name === 'default' && str_starts_with($route->domain, $app->slug.'.')) {
            return $newSlug.substr($route->domain, strlen($app->slug));
        }

        $prefix = $name.'.'.$app->slug.'.';

        if (str_starts_with($route->domain, $prefix)) {
            return $name.'.'.$newSlug.'.'.substr($route->domain, strlen($prefix));
        }

        return str_replace($app->slug, $newSlug, $route->domain);
    }

    private function captureEnvironment(int $instanceId): ?string
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

    private function writeEnvironment(int $instanceId, string $url): void
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

    private function projectRuntime(int $instanceId, mixed $root = null): void
    {
        $this->localTuning[$instanceId] ??= 'operator-local.conf';
        $this->runtimeProjections[] = [
            'instance_id' => $instanceId,
            'root' => is_string($root) ? $root : null,
            'validated' => true,
            'preserved_tuning' => $this->localTuning[$instanceId] === 'operator-local.conf',
        ];
    }

    private function effectiveRoot(AppInstance $instance, ?string $appRoot): ?string
    {
        $root = $instance->root ?? $appRoot;

        if ($instance->environment === 'production' && is_string($instance->production_home) && is_string($root)) {
            $base = $instance->usesProductionReleaseLayout()
                ? "{$instance->production_home}/current"
                : $instance->production_home;

            return "{$base}/{$root}";
        }

        return $root;
    }
}
