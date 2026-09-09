<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Actions\Routes\CreateRouteAction;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\DevelopmentAppInstanceConfigurator;
use App\Domain\AppInstances\DevelopmentAppInstanceProvisioner;
use App\Domain\AppInstances\DevelopmentRouteProjector;
use App\Domain\AppInstances\DevelopmentSourceProfile;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use App\Models\Route;
use Illuminate\Support\Facades\DB;

/** @mago-expect lint:cyclomatic-complexity Completion keeps source-profile recovery inside the owned publication lifecycle. */
final readonly class NativeDevelopmentAppInstanceProvisioner implements DevelopmentAppInstanceProvisioner
{
    public function __construct(
        private CreateRouteAction $routes,
        private DevelopmentAppInstanceConfigurator $configuration,
        private DevelopmentRouteProjector $projection,
        private ?DevelopmentProjectionOperationLock $projectionOwner = null,
    ) {}

    public function reserve(AppInstance $appInstance, ?string $hostname): void
    {
        $route = $this->routes->ensureForAppInstance($appInstance, $hostname);

        if ($route->status === RouteStatus::Failed) {
            $route->update(['status' => RouteStatus::Pending, 'failed_step' => null, 'error_code' => null]);
        }
    }

    public function complete(
        AppInstance $appInstance,
        ?string $hostname,
        bool $recoverSourceProfile = false,
    ): AppInstance {
        $route = $this->routes->ensureForAppInstance($appInstance, $hostname);

        return $this->owner()->run(
            fn (): AppInstance => $this->completeOwned(
                $appInstance->id,
                $route->id,
                $recoverSourceProfile,
            ),
        );
    }

    private function completeOwned(
        int $appInstanceId,
        int $routeId,
        bool $recoverSourceProfile,
    ): AppInstance {
        $appInstance = AppInstance::query()->with('node')->findOrFail($appInstanceId);
        $route = Route::query()
            ->with(['targets.appInstance.node', 'cluster.routerAssignment.node'])
            ->whereKey($routeId)
            ->whereHas('targets', static fn ($query) => $query->where('app_instance_id', $appInstanceId))
            ->first();

        if (! $route instanceof Route) {
            throw new ResourceOperationException(
                errorCode: 'instance.lifecycle_conflict',
                message: 'The AppInstance Route changed before development projection began.',
                status: 409,
            );
        }

        if ($appInstance->status === AppInstanceState::Active && $route->status === RouteStatus::Active) {
            return $appInstance->load('routes.targets');
        }

        if (
            $appInstance->provisioning_step !== null
            && ! in_array($appInstance->provisioning_step, ['php-selected', 'url-configured'], strict: true)
        ) {
            throw $this->sourceEvidenceChanged();
        }

        $legacyIncompleteProfile = $appInstance->provisioning_step !== null && $appInstance->source_is_laravel === null;

        if ($legacyIncompleteProfile && ! $recoverSourceProfile) {
            throw $this->sourceEvidenceChanged();
        }

        $profile = $this->configuration->inspect($appInstance);

        if ($appInstance->provisioning_step === null || $legacyIncompleteProfile) {
            $this->recordProfile($appInstance, $profile);
        } elseif (
            $appInstance->selected_php_version !== $profile->phpVersion
            || $appInstance->source_is_laravel !== $profile->laravel
        ) {
            throw $this->sourceEvidenceChanged();
        }

        if ($appInstance->provisioning_step === 'php-selected') {
            if ($profile->laravel) {
                $this->configuration->configureLaravelUrl($appInstance, "https://{$route->hostname}");
            }

            $appInstance->update(['provisioning_step' => 'url-configured']);
        }
        $this->projection->converge($appInstance->refresh(), $route->refresh());

        DB::transaction(static function () use ($appInstance, $route): void {
            $lockedInstance = AppInstance::query()->lockForUpdate()->findOrFail($appInstance->id);
            $lockedRoute = Route::query()->lockForUpdate()->findOrFail($route->id);
            $lockedRoute->update([
                'status' => RouteStatus::Active,
                'failed_step' => null,
                'error_code' => null,
            ]);
            $lockedInstance->update([
                'status' => AppInstanceState::Active,
                'provisioning_step' => 'active',
                'failed_step' => null,
                'error_code' => null,
            ]);
        });

        return $appInstance->refresh()->load('routes.targets');
    }

    private function owner(): DevelopmentProjectionOperationLock
    {
        return $this->projectionOwner ?? app(DevelopmentProjectionOperationLock::class);
    }

    private function recordProfile(
        AppInstance $appInstance,
        DevelopmentSourceProfile $profile,
    ): void {
        $appInstance->update([
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
