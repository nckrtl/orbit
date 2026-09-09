<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Actions\Routes\CreateRouteAction;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\DevelopmentAppInstanceConfigurator;
use App\Domain\AppInstances\DevelopmentAppInstanceProvisioner;
use App\Domain\AppInstances\DevelopmentRouteProjector;
use App\Domain\AppInstances\DevelopmentSourceProfile;
use App\Domain\Routes\RouteStatus;
use App\Models\AppInstance;
use App\Models\Route;
use Illuminate\Support\Facades\DB;

final readonly class NativeDevelopmentAppInstanceProvisioner implements DevelopmentAppInstanceProvisioner
{
    public function __construct(
        private CreateRouteAction $routes,
        private DevelopmentAppInstanceConfigurator $configuration,
        private DevelopmentRouteProjector $projection,
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
