<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Actions\Routes\CreateRouteAction;
use App\Actions\Routes\UpdateRouteAction;
use App\Data\Routes\UpdateRouteData;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppDev\VitePortAllocator;
use App\Domain\Instances\DevelopmentInstanceConfigurator;
use App\Domain\Instances\DevelopmentInstanceProvisioner;
use App\Domain\Instances\DevelopmentRouteProjector;
use App\Domain\Instances\InstanceState;
use App\Domain\Projects\ProjectApps;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RouteStateResolver;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Route;
use Illuminate\Support\Facades\DB;

final readonly class NativeDevelopmentInstanceProvisioner implements DevelopmentInstanceProvisioner
{
    public function __construct(
        private CreateRouteAction $routes,
        private DevelopmentInstanceConfigurator $configuration,
        private DevelopmentRouteProjector $projection,
        private ?DevelopmentProjectionOperationLock $projectionOwner = null,
    ) {}

    public function reserve(Instance $instance, ?string $domain): void
    {
        if ($domain !== null) {
            $instance->appConfiguration();
        }
        if ($instance->task_workspace_routed !== false && $domain === null) {
            foreach ($instance->effectiveApps() as $app) {
                if (! ProjectApps::isServing($app)) {
                    continue;
                }
                $domainName = app(RouteStateResolver::class)->generatedDomain($instance->project->slug, $instance->name, app(RouteStateResolver::class)->forNode($instance->node)->effectiveTld, $app['name']);
                $occupied = Route::query()->where('domain', $domainName)->first();
                if ($occupied instanceof Route && ($occupied->project_id !== $instance->project_id || $occupied->app !== $app['name'] || ! $occupied->targets()->where('instance_id', $instance->id)->exists())) {
                    throw new ResourceOperationException('route.domain_conflict', 'A required app domain is already owned.', 409);
                }
            }
        }
        foreach ($instance->effectiveApps() as $app) {
            if ($instance->task_workspace_routed === false || ! ProjectApps::isServing($app)) {
                continue;
            }
            app(VitePortAllocator::class)->assign($instance, app: $app['name']);
            $route = $this->routes->ensureForInstance($instance, $domain, $app['name']);
            if ($route->status === RouteStatus::Failed) {
                $route->update(['status' => RouteStatus::Pending, 'failed_step' => null, 'error_code' => null]);
            }
        }
    }

    public function complete(Instance $instance, ?string $domain, bool $setupPending = false): Instance
    {
        $this->reserve($instance, $domain);
        $expected = $instance->routes()->get()->keyBy('app')->map(static fn (Route $route): int => $route->id)->all();

        return ($this->projectionOwner ?? app(DevelopmentProjectionOperationLock::class))->run(function () use ($instance, $expected, $setupPending): Instance {
            $instance = Instance::query()->with(['project', 'node'])->findOrFail($instance->id);
            $completed = [];
            foreach ($instance->effectiveApps() as $app) {
                $name = $app['name'];
                $serving = $instance->task_workspace_routed !== false && ProjectApps::isServing($app);
                $route = $serving ? $instance->routes()->where('routes.id', $expected[$name] ?? 0)->where('routes.app', $name)->first() : null;
                if ($serving && ! $route instanceof Route) {
                    throw new ResourceOperationException('instance.lifecycle_conflict', 'The Instance app Route changed before projection began.', 409);
                }
                $generatedDomain = $serving && $route->provenance === RouteProvenance::Generated
                    ? app(RouteStateResolver::class)->generatedDomain($instance->project->slug, $instance->name, app(RouteStateResolver::class)->forNode($instance->node)->effectiveTld, $name)
                    : null;
                if ($instance->status === InstanceState::Active && (! $serving || $route->status === RouteStatus::Active && $instance->usesAppRuntimeIdentity($name) && ($instance->app_runtime[$name]['app_identity_ready'] ?? true) && ($generatedDomain === null || $generatedDomain === $route->domain))) {
                    continue;
                }
                $runtime = $instance->app_runtime[$name] ?? [];
                if (count($instance->effectiveApps()) === 1 && ! isset($runtime['step']) && in_array($instance->provisioning_step, ['php-selected', 'url-configured'], true)) {
                    $runtime = ['step' => $instance->provisioning_step, 'php_version' => $instance->selected_php_version, 'laravel' => $instance->source_is_laravel];
                }
                $step = $runtime['step'] ?? null;
                if ($step !== null && ! is_bool($runtime['laravel'] ?? null)) {
                    throw new RuntimeConvergenceException('source-classification', 'app-dev.source_evidence_changed', "Missing source evidence for app [{$name}].");
                }
                $profile = $this->configuration->inspect($instance, $name);
                if ($step !== null && (($runtime['php_version'] ?? null) !== $profile->phpVersion || ($runtime['laravel'] ?? null) !== $profile->laravel)) {
                    throw new RuntimeConvergenceException('source-classification', 'app-dev.source_evidence_changed', "Source evidence changed for app [{$name}].");
                }
                if ($step === null) {
                    $instance->recordAppRuntime($name, ['php_version' => $profile->phpVersion, 'laravel' => $profile->laravel, 'step' => 'php-selected']);
                    if (count($instance->effectiveApps()) === 1) {
                        $instance->update(['selected_php_version' => $profile->phpVersion, 'source_is_laravel' => $profile->laravel, 'provisioning_step' => 'php-selected']);
                    }
                }
                if ($serving) {
                    if ($step !== 'url-configured' && $profile->laravel) {
                        $this->configuration->configureLaravelUrl($instance, "https://{$route->domain}", $name);
                    }
                    $instance->recordAppRuntime($name, ['step' => 'url-configured']);
                    if (count($instance->effectiveApps()) === 1) {
                        $instance->update(['provisioning_step' => 'url-configured']);
                    }
                    $this->projection->converge($instance->refresh(), $route->refresh());
                    if ($instance->status === InstanceState::Active && $generatedDomain !== null && $generatedDomain !== $route->domain) {
                        $route = app(UpdateRouteAction::class)->execute($route, new UpdateRouteData(true, $generatedDomain, false, null), allowGenerated: true);
                    }
                    $completed[] = $route->id;
                }
            }
            DB::transaction(static function () use ($instance, $completed, $setupPending): void {
                $locked = Instance::query()->lockForUpdate()->findOrFail($instance->id);
                foreach ($completed as $id) {
                    Route::query()->lockForUpdate()->findOrFail($id)->update(['status' => RouteStatus::Active, 'failed_step' => null, 'error_code' => null]);
                }
                $locked->update(['status' => InstanceState::Active, 'provisioning_step' => 'active', 'failed_step' => $setupPending ? 'setup' : null, 'error_code' => null]);
            });

            return $instance->refresh()->load('routes.targets');
        });
    }
}
