<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Actions\Routes\CreateRouteAction;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppDev\SsrPortAllocator;
use App\Domain\AppDev\VitePortAllocator;
use App\Domain\Instances\DevelopmentInstanceConfigurator;
use App\Domain\Instances\DevelopmentInstanceProvisioner;
use App\Domain\Instances\DevelopmentRouteProjector;
use App\Domain\Instances\DevelopmentSourceProfile;
use App\Domain\Instances\InstanceState;
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
        app(VitePortAllocator::class)->assign($instance);
        app(SsrPortAllocator::class)->assign($instance);

        if (! $instance->requiresRoute()) {
            return;
        }

        $route = $this->routes->ensureForInstance($instance, $domain);

        if ($route->status === RouteStatus::Failed) {
            $route->update(['status' => RouteStatus::Pending, 'failed_step' => null, 'error_code' => null]);
        }
    }

    public function complete(
        Instance $instance,
        ?string $domain,
        bool $setupPending = false,
    ): Instance {
        if (! $instance->requiresRoute()) {
            return $this->owner()->run(
                fn (): Instance => $this->completeWithoutRoute($instance->id, $setupPending),
            );
        }

        $route = $this->routes->ensureForInstance($instance, $domain);

        return $this->owner()->run(
            fn (): Instance => $this->completeOwned(
                $instance->id,
                $route->id,
                $setupPending,
            ),
        );
    }

    private function completeWithoutRoute(int $instanceId, bool $setupPending): Instance
    {
        $instance = Instance::query()->with(['project', 'node'])->findOrFail($instanceId);

        if ($instance->status === InstanceState::Active) {
            return $instance->load('routes.targets');
        }

        $profile = $this->configuration->inspect($instance);
        $this->recordProfile($instance, $profile);

        DB::transaction(static function () use ($instance, $setupPending): void {
            $lockedInstance = Instance::query()->lockForUpdate()->findOrFail($instance->id);
            $lockedInstance->update([
                'status' => InstanceState::Active,
                'provisioning_step' => 'active',
                'failed_step' => $setupPending ? 'setup' : null,
                'error_code' => null,
            ]);
        });

        return $instance->refresh()->load('routes.targets');
    }

    private function completeOwned(
        int $instanceId,
        int $routeId,
        bool $setupPending,
    ): Instance {
        $instance = Instance::query()->with('node')->findOrFail($instanceId);
        $route = Route::query()
            ->with(['targets.instance.node', 'cluster.routerAssignment.node'])
            ->whereKey($routeId)
            ->whereHas('targets', static fn ($query) => $query->where('instance_id', $instanceId))
            ->first();

        if (! $route instanceof Route) {
            throw new ResourceOperationException(
                errorCode: 'instance.lifecycle_conflict',
                message: 'The Instance Route changed before development projection began.',
                status: 409,
            );
        }

        if ($instance->status === InstanceState::Active && $route->status === RouteStatus::Active) {
            return $instance->load('routes.targets');
        }

        if (
            $instance->provisioning_step !== null
            && ! in_array($instance->provisioning_step, ['php-selected', 'url-configured'], strict: true)
        ) {
            throw $this->sourceEvidenceChanged();
        }

        if ($instance->provisioning_step !== null && $instance->source_is_laravel === null) {
            throw $this->sourceEvidenceChanged();
        }

        $profile = $this->configuration->inspect($instance);

        if ($instance->provisioning_step === null) {
            $this->recordProfile($instance, $profile);
        } elseif (
            $instance->selected_php_version !== $profile->phpVersion
            || $instance->source_is_laravel !== $profile->laravel
        ) {
            throw $this->sourceEvidenceChanged();
        }

        if ($instance->provisioning_step === 'php-selected') {
            if ($profile->laravel) {
                $this->configuration->configureLaravelUrl($instance, "https://{$route->domain}");
            }

            $instance->update(['provisioning_step' => 'url-configured']);
        }
        $this->projection->converge($instance->refresh(), $route->refresh());

        DB::transaction(static function () use ($instance, $route, $setupPending): void {
            $lockedInstance = Instance::query()->lockForUpdate()->findOrFail($instance->id);
            $lockedRoute = Route::query()->lockForUpdate()->findOrFail($route->id);
            $lockedRoute->update([
                'status' => RouteStatus::Active,
                'failed_step' => null,
                'error_code' => null,
            ]);
            $lockedInstance->update([
                'status' => InstanceState::Active,
                'provisioning_step' => 'active',
                'failed_step' => $setupPending ? 'setup' : null,
                'error_code' => null,
            ]);
        });

        return $instance->refresh()->load('routes.targets');
    }

    private function owner(): DevelopmentProjectionOperationLock
    {
        return $this->projectionOwner ?? app(DevelopmentProjectionOperationLock::class);
    }

    private function recordProfile(
        Instance $instance,
        DevelopmentSourceProfile $profile,
    ): void {
        $instance->update([
            'selected_php_version' => $profile->phpVersion,
            'source_is_laravel' => $profile->laravel,
            'provisioning_step' => 'php-selected',
            'failed_step' => null,
            'error_code' => null,
        ]);
    }

    private function sourceEvidenceChanged(): RuntimeConvergenceException
    {
        return new RuntimeConvergenceException(
            step: 'source-classification',
            errorCode: 'app-dev.source_evidence_changed',
            message: 'The development source classification changed after provisioning began.',
        );
    }
}
