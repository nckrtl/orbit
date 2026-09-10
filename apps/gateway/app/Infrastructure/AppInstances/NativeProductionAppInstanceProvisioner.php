<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Actions\Routes\CreateRouteAction;
use App\Data\AppInstances\CreateAppInstanceData;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\DevelopmentSourceResolution;
use App\Domain\AppInstances\ProductionAppInstanceProvisioner;
use App\Domain\AppInstances\ProductionAppInstanceSourceLifecycle;
use App\Domain\AppInstances\ProductionRouteProjector;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteStateResolver;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppProd\AppProdSiteRepository;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class NativeProductionAppInstanceProvisioner implements ProductionAppInstanceProvisioner
{
    public function __construct(
        private AppDevSourceOperationLock $sourceLock,
        private ProductionAppInstanceSourceLifecycle $source,
        private CreateRouteAction $routes,
        private RouteStateResolver $routeState,
        private AppProdSiteRepository $appProdSites,
        private ProductionRouteProjector $projection,
        private ?DevelopmentProjectionOperationLock $projectionOwner = null,
    ) {}

    /** @return array{appInstance: AppInstance, created: bool} */
    public function execute(CreateAppInstanceData $data, App $app, Node $node, ?string $root): array
    {
        $this->assertPlacement($node);
        $this->preflightLegacyProduction($node);
        $this->preflightHostname($app, $node, $data);
        [$appInstance, $created] = $this->reserve($app, $node, $data, $root);

        if ($appInstance->status === AppInstanceState::Active) {
            $this->routes->ensureForAppInstance($appInstance, $data->hostname);

            return ['appInstance' => $appInstance->load('routes.targets'), 'created' => false];
        }

        $result = $this->sourceLock->synchronized(
            $node->id,
            function () use ($appInstance, $created, $data): AppInstance {
                try {
                    return $this->resume($appInstance, $created, $data->hostname);
                } catch (Throwable $exception) {
                    $this->recordFailure($appInstance, $exception);

                    throw $exception;
                }
            },
        );

        return ['appInstance' => $result, 'created' => $created];
    }

    private function assertPlacement(Node $node): void
    {
        if ($node->status !== LifecycleStatus::Active || $node->platform !== 'linux') {
            throw $this->conflict('instance.node_inactive', 'The selected app-prod Node is not active.');
        }

        if (! $node->roles()->where('role', RoleName::AppProd)->where('status', LifecycleStatus::Active)->exists()) {
            throw $this->conflict('instance.node_not_app_prod', 'The selected Node has no active app-prod role.');
        }

        if ($this->routeState->forNode($node)->clusterId !== null) {
            throw $this->conflict(
                'instance.cluster_production_unavailable',
                'Production AppInstance creation currently requires a standalone Node.',
            );
        }
    }

    private function preflightLegacyProduction(Node $node): void
    {
        if (! $this->appProdSites->hasLivePublicFootprint($node)) {
            return;
        }

        throw $this->conflict(
            'instance.legacy_production_conflict',
            'The selected Node still serves a legacy public production Instance.',
        );
    }

    private function preflightHostname(App $app, Node $node, CreateAppInstanceData $data): void
    {
        if ($data->hostname !== null) {
            return;
        }

        $placement = $this->routeState->forNode($node);
        $this->routeState->generatedHostname($app->slug, $data->name, $placement->effectiveTld);
    }

    /** @return array{AppInstance, bool} */
    private function reserve(App $app, Node $node, CreateAppInstanceData $data, ?string $root): array
    {
        $expectedUser = "orbit-app-{$app->id}";
        $expectedHome = "/home/{$expectedUser}";
        $existing = AppInstance::query()
            ->where('app_id', $app->id)
            ->where('name', $data->name)
            ->first();

        if ($existing instanceof AppInstance) {
            $this->assertRetryIdentity($existing, $node, $root, $data->branch, $expectedUser, $expectedHome);

            return [$existing, false];
        }

        $occupiedPlacement = AppInstance::query()
            ->where('app_id', $app->id)
            ->where('node_id', $node->id)
            ->where('environment', 'production')
            ->first();

        if ($occupiedPlacement instanceof AppInstance) {
            throw $this->conflict(
                'instance.production_placement_conflict',
                'The App already has a production AppInstance on the selected Node.',
            );
        }

        try {
            $instance = AppInstance::query()->create([
                'app_id' => $app->id,
                'node_id' => $node->id,
                'name' => $data->name,
                'environment' => 'production',
                'source_layout' => AppInstanceSourceLayout::Checkout,
                'checkout_path' => $expectedHome,
                'production_user' => $expectedUser,
                'production_home' => $expectedHome,
                'root' => $root,
                'branch_override' => $data->branch,
                'status' => AppInstanceState::Reserved,
            ]);
        } catch (QueryException $exception) {
            throw new ResourceOperationException(
                'instance.production_placement_conflict',
                'The production AppInstance placement conflicts with an existing record.',
                409,
                $exception,
            );
        }

        return [$instance, true];
    }

    private function assertRetryIdentity(
        AppInstance $appInstance,
        Node $node,
        ?string $root,
        ?string $branchOverride,
        string $expectedUser,
        string $expectedHome,
    ): void {
        if ($appInstance->status === AppInstanceState::Removing) {
            throw $this->conflict('instance.removal_conflict', 'The AppInstance is being removed.');
        }

        if (
            $appInstance->environment !== 'production'
            || $appInstance->node_id !== $node->id
            || $appInstance->source_layout !== AppInstanceSourceLayout::Checkout->value
            || $appInstance->checkout_path !== $expectedHome
            || $appInstance->production_user !== $expectedUser
            || $appInstance->production_home !== $expectedHome
            || $appInstance->root !== $root
            || $appInstance->branch_override !== $branchOverride
        ) {
            throw $this->conflict('instance.placement_conflict', 'AppInstance placement is immutable.');
        }
    }

    private function resume(AppInstance $appInstance, bool $created, ?string $hostname): AppInstance
    {
        $appInstance->refresh()->loadMissing(['app', 'node']);

        if ($appInstance->provisioning_step === null) {
            $this->source->prepareUser($appInstance);
            $this->checkpoint($appInstance, 'user-prepared');
        }

        if ($appInstance->provisioning_step === 'user-prepared') {
            $this->source->prepareSource($appInstance, ! $created);
            $this->checkpoint($appInstance, 'source-prepared', AppInstanceState::CheckoutPrepared);
        }

        if ($appInstance->provisioning_step === 'source-prepared') {
            $resolution = $this->source->resolve($appInstance);
            $this->assertResolution($appInstance, $resolution);
            $this->checkpoint($appInstance, 'source-resolved', AppInstanceState::SourceResolved, [
                'branch' => $resolution->branch,
                'starting_commit' => $resolution->startingCommit,
            ]);
        }

        if ($appInstance->provisioning_step === 'source-resolved') {
            $profile = $this->source->inspectProfile($appInstance);
            $this->checkpoint($appInstance, 'source-classified', attributes: [
                'selected_php_version' => $profile->phpVersion,
                'source_is_laravel' => $profile->laravel,
            ]);
        }

        if ($appInstance->source_is_laravel === true) {
            throw $this->conflict(
                'app-prod.laravel_activation_unavailable',
                'Laravel production activation is not available through this creation path.',
            );
        }

        if ($appInstance->provisioning_step === 'source-classified') {
            $this->source->prepareCaddyAccess($appInstance);
        }

        $route = $this->routes->ensureForAppInstance($appInstance, $hostname);

        if ($route->status === RouteStatus::Failed) {
            $route->update(['status' => RouteStatus::Pending, 'failed_step' => null, 'error_code' => null]);
        }

        if ($appInstance->provisioning_step === 'source-classified') {
            $this->projection->prepareRuntime($appInstance, $route);
            $this->checkpoint($appInstance, 'runtime-prepared');
        }

        if ($appInstance->provisioning_step === 'runtime-prepared') {
            $this->projection->prepareCertificate($appInstance, $route);
            $this->checkpoint($appInstance, 'certificate-prepared');
        }

        if ($appInstance->provisioning_step === 'certificate-prepared') {
            $this->projection->prepareFirewall($appInstance);
            $this->checkpoint($appInstance, 'firewall-prepared');
        }

        if (in_array($appInstance->provisioning_step, ['firewall-prepared', 'route-published'], strict: true)) {
            $this->completePublication($appInstance->id, $route->id);
        }

        return $appInstance->refresh()->load('routes.targets');
    }

    private function completePublication(int $appInstanceId, int $routeId): void
    {
        $this->projectionOwner()->run(
            fn () => $this->completePublicationOwned($appInstanceId, $routeId),
        );
    }

    private function completePublicationOwned(int $appInstanceId, int $routeId): void
    {
        $appInstance = AppInstance::query()->with('node')->findOrFail($appInstanceId);
        $route = Route::query()
            ->with(['targets.appInstance.node', 'cluster.routerAssignment.node'])
            ->whereKey($routeId)
            ->whereHas('targets', static fn ($query) => $query->where('app_instance_id', $appInstanceId))
            ->first();

        if (! $route instanceof Route) {
            throw $this->conflict(
                'instance.lifecycle_conflict',
                'The AppInstance Route changed before production projection began.',
            );
        }

        if (! in_array($appInstance->provisioning_step, ['firewall-prepared', 'route-published'], strict: true)) {
            throw $this->conflict(
                'instance.lifecycle_conflict',
                'The AppInstance lifecycle changed before production projection began.',
            );
        }

        $this->projection->publish($appInstance, $route);

        if ($appInstance->provisioning_step === 'firewall-prepared') {
            $this->checkpoint($appInstance, 'route-published');
        }

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
    }

    private function projectionOwner(): DevelopmentProjectionOperationLock
    {
        return $this->projectionOwner ?? app(DevelopmentProjectionOperationLock::class);
    }

    /** @param array<string, mixed> $attributes */
    private function checkpoint(
        AppInstance $appInstance,
        string $step,
        ?AppInstanceState $status = null,
        array $attributes = [],
    ): void {
        $appInstance->update([
            ...$attributes,
            'provisioning_step' => $step,
            'failed_step' => null,
            'error_code' => null,
            ...($status instanceof AppInstanceState ? ['status' => $status] : []),
        ]);
        $appInstance->refresh();
    }

    private function assertResolution(AppInstance $appInstance, DevelopmentSourceResolution $resolution): void
    {
        $expectedBranch = $appInstance->branch_override ?? $appInstance->app->default_branch;

        if (
            $resolution->branch !== $expectedBranch
            || preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $resolution->startingCommit) !== 1
        ) {
            throw $this->conflict('instance.source_identity_invalid', 'Resolved source identity is invalid.');
        }
    }

    private function recordFailure(AppInstance $appInstance, Throwable $exception): void
    {
        $step = property_exists($exception, 'step') && is_string($exception->step)
            ? $exception->step
            : $appInstance->refresh()->provisioning_step ?? 'production-provisioning';
        $errorCode = property_exists($exception, 'errorCode') && is_string($exception->errorCode)
            ? $exception->errorCode
            : 'instance.provisioning_failed';

        DB::transaction(static function () use ($appInstance, $step, $errorCode): void {
            AppInstance::query()
                ->whereKey($appInstance->id)
                ->update([
                    'failed_step' => $step,
                    'error_code' => $errorCode,
                ]);
            Route::query()
                ->whereHas('targets', static fn ($query) => $query->where('app_instance_id', $appInstance->id))
                ->where('status', '<>', RouteStatus::Active->value)
                ->update([
                    'status' => RouteStatus::Failed,
                    'failed_step' => $step,
                    'error_code' => $errorCode,
                ]);
        });
    }

    private function conflict(string $errorCode, string $message): ResourceOperationException
    {
        return new ResourceOperationException($errorCode, $message, 409);
    }
}
