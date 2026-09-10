<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Data\AppInstances\PrepareAppInstanceDeploymentLayoutData;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\DeploymentLayout\DeploymentLayoutInventory;
use App\Domain\AppInstances\DeploymentLayout\DeploymentLayoutStep;
use App\Domain\AppInstances\DeploymentLayout\ProductionLayoutConverter;
use App\Domain\AppInstances\DeploymentLayout\ProductionPhpRuntimeAdopter;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentContextResolver;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentImporter;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentReader;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentRenderer;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentStore;
use App\Domain\AppInstances\ProductionAppInstanceSourceLifecycle;
use App\Domain\AppInstances\ProductionPhpRuntimeIdentity;
use App\Domain\AppInstances\ProductionReleaseLayout;
use App\Domain\AppInstances\ProductionRouteProjector;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use App\Models\AppInstanceDeploymentLayout;
use App\Models\Process;
use App\Models\Route;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class AdoptProductionLayoutAction
{
    public function __construct(
        private AppInstanceEnvironmentOperationLock $operations,
        private ProcessAdmissionLock $processAdmission,
        private AppInstanceEnvironmentContextResolver $contexts,
        private AppInstanceEnvironmentStore $environmentStore,
        private AppInstanceEnvironmentRenderer $environmentRenderer,
        private AppInstanceEnvironmentImporter $environmentImporter,
        private AppInstanceEnvironmentReader $environmentReader,
        private ProductionLayoutConverter $converter,
        private ProductionPhpRuntimeAdopter $runtime,
        private ProductionAppInstanceSourceLifecycle $source,
        private ProductionRouteProjector $projection,
        private ProductionReleaseLayout $releaseLayout,
        private DevelopmentProjectionOperationLock $projectionOwner,
    ) {}

    public function execute(
        AppInstance $appInstance,
        PrepareAppInstanceDeploymentLayoutData $data,
    ): AppInstance {
        return $this->operations->run(
            [$appInstance->id],
            fn (): AppInstance => $data->sqliteSourcePath === null
                ? $this->resume($appInstance->id, $data)
                : $this->processAdmission->run(
                    [$appInstance->id],
                    fn (): AppInstance => $this->resume($appInstance->id, $data),
                ),
        );
    }

    private function resume(int $appInstanceId, PrepareAppInstanceDeploymentLayoutData $data): AppInstance
    {
        $record = AppInstanceDeploymentLayout::query()->where('app_instance_id', $appInstanceId)->first();

        if ($record instanceof AppInstanceDeploymentLayout) {
            $this->assertRequestIdentity($record, $data);

            if ($record->step === DeploymentLayoutStep::Completed) {
                return $this->validateCompleted($record);
            }
        } else {
            $record = $this->accept($appInstanceId, $data);
        }

        try {
            $inventory = DeploymentLayoutInventory::fromArray($record->inventory);
            $appInstance = $this->activeProductionInstance($appInstanceId);

            if ($record->step === DeploymentLayoutStep::Accepted) {
                $this->converter->moveSource($appInstance, $inventory);
                $this->checkpoint($record, DeploymentLayoutStep::SourceMoved);
            }

            if ($record->step === DeploymentLayoutStep::SourceMoved) {
                if ($inventory->sqliteSourcePath !== null) {
                    $this->assertNoActiveProcesses($appInstance);
                    $this->converter->assertSqliteQuiescent($appInstance, $inventory);
                }

                $this->converter->placePersistentState($appInstance, $inventory);
                $this->checkpoint($record, DeploymentLayoutStep::PersistentStatePlaced);
            }

            if ($record->step === DeploymentLayoutStep::PersistentStatePlaced) {
                $appInstance = $this->recordReleasePlacement($appInstanceId, $inventory);
                $identity = ProductionPhpRuntimeIdentity::forProvisioning(
                    $appInstance,
                    (string) $appInstance->selected_php_version,
                );
                $runtimeCandidate = clone $appInstance;
                $runtimeCandidate->forceFill($identity->attributes());
                $this->runtime->adopt(
                    $runtimeCandidate,
                    $this->converter->runtimeTuning($runtimeCandidate, $inventory),
                );
                $appInstance->update($identity->attributes());
                $this->checkpoint($record, DeploymentLayoutStep::RuntimePublished);
            }

            if ($record->step === DeploymentLayoutStep::RuntimePublished) {
                $this->projectionOwner->run(function () use ($appInstanceId, $inventory): void {
                    $appInstance = $this->activeProductionInstance($appInstanceId);
                    $route = $this->recordedRoute($appInstance, $inventory);
                    $this->converter->validateServingAssociation($appInstance, $inventory);
                    $this->source->prepareCaddyAccess($appInstance);
                    $this->projection->publish($appInstance, $route);
                });
                $this->checkpoint($record, DeploymentLayoutStep::RouteProjected);
            }

            if ($record->step === DeploymentLayoutStep::RouteProjected) {
                $this->validateConvertedState($appInstanceId, $inventory);
                $this->checkpoint($record, DeploymentLayoutStep::Completed, completed: true);
            }

            return $this->validateCompleted($record->refresh());
        } catch (Throwable $exception) {
            $record->update([
                'failed_step' => $record->step->value,
                'error_code' => $exception instanceof ResourceOperationException
                    ? $exception->errorCode
                    : 'deployment_layout.interrupted',
            ]);

            throw $exception;
        }
    }

    private function accept(
        int $appInstanceId,
        PrepareAppInstanceDeploymentLayoutData $data,
    ): AppInstanceDeploymentLayout {
        $appInstance = $this->activeProductionInstance($appInstanceId);

        if ($appInstance->usesProductionReleaseLayout()) {
            throw $this->conflict('deployment_layout.not_convertible', 'The AppInstance already uses a release layout.');
        }

        if ($appInstance->checkout_path !== $appInstance->production_home) {
            throw $this->conflict('deployment_layout.placement_invalid', 'The production source placement is not convertible.');
        }

        $context = $this->contexts->resolve($appInstance, requireActiveNode: true);
        $snapshot = $this->environmentStore->synchronizationSnapshot($context);
        $expectedEnvironment = $this->environmentRenderer->render($context, $snapshot->values());
        $actualEnvironment = $this->environmentReader->read($context);

        if ($this->environmentImporter->parse($expectedEnvironment) !== $this->environmentImporter->parse($actualEnvironment)) {
            throw $this->conflict(
                'deployment_layout.environment_mismatch',
                'The stored and local AppInstance environment values do not agree.',
            );
        }

        $route = $this->soleActiveRoute($appInstance);
        if ($data->sqliteSourcePath !== null) {
            $this->assertNoActiveProcesses($appInstance);
        }
        $inventory = $this->converter->preflight(
            $appInstance,
            $route,
            $actualEnvironment,
            $data->sqliteSourcePath,
        );

        return DB::transaction(static function () use ($appInstance, $data, $inventory): AppInstanceDeploymentLayout {
            $locked = AppInstance::query()->lockForUpdate()->findOrFail($appInstance->id);

            if (
                $locked->status !== AppInstanceState::Active
                || $locked->checkout_path !== $inventory->sourcePath
                || $locked->usesProductionReleaseLayout()
            ) {
                throw new ResourceOperationException(
                    'deployment_layout.owner_changed',
                    'The AppInstance changed while conversion was being accepted.',
                    409,
                );
            }

            return AppInstanceDeploymentLayout::query()->create([
                'app_instance_id' => $locked->id,
                'step' => DeploymentLayoutStep::Accepted,
                'source_path' => $inventory->sourcePath,
                'release_path' => $inventory->releasePath,
                'sqlite_source_path' => $data->sqliteSourcePath,
                'inventory' => $inventory->toArray(),
            ]);
        });
    }

    private function activeProductionInstance(int $id): AppInstance
    {
        $appInstance = AppInstance::query()->with(['app', 'node'])->findOrFail($id);

        if (
            $appInstance->environment !== 'production'
            || $appInstance->status !== AppInstanceState::Active
            || $appInstance->migration_required
            || $appInstance->provisioning_step !== 'active'
            || ! is_string($appInstance->production_user)
            || ! is_string($appInstance->production_home)
            || ! is_string($appInstance->selected_php_version)
        ) {
            throw $this->conflict(
                'deployment_layout.owner_unavailable',
                'The AppInstance is not an active convertible production placement.',
            );
        }

        return $appInstance;
    }

    private function soleActiveRoute(AppInstance $appInstance): Route
    {
        $context = $this->contexts->resolve($appInstance, requireActiveNode: true);
        $route = Route::query()->find($context->routeId);

        if (! $route instanceof Route) {
            throw $this->conflict('deployment_layout.serving_conflict', 'The AppInstance serving association changed.');
        }

        return $route;
    }

    private function assertNoActiveProcesses(AppInstance $appInstance): void
    {
        $active = Process::query()
            ->where('owner_type', AppInstance::class)
            ->where('owner_id', $appInstance->id)
            ->where(static fn (Builder $query): Builder => $query
                ->where('desired_state', DesiredProcessState::Running)
                ->orWhereIn('status', [LifecycleStatus::Provisioning, LifecycleStatus::Active]))
            ->exists();

        if ($active) {
            throw $this->conflict(
                'deployment_layout.process_active',
                'An active AppInstance Process prevents deployment-layout conversion.',
            );
        }
    }

    private function recordReleasePlacement(int $appInstanceId, DeploymentLayoutInventory $inventory): AppInstance
    {
        return DB::transaction(static function () use ($appInstanceId, $inventory): AppInstance {
            $appInstance = AppInstance::query()->lockForUpdate()->findOrFail($appInstanceId);
            $appInstance->update(['checkout_path' => $inventory->releasePath]);

            return $appInstance->refresh()->load(['app', 'node']);
        });
    }

    private function recordedRoute(AppInstance $appInstance, DeploymentLayoutInventory $inventory): Route
    {
        $route = Route::query()
            ->with('targets')
            ->whereKey($inventory->routeId)
            ->whereHas('targets', static fn (Builder $query): Builder => $query
                ->where('app_instance_id', $appInstance->id))
            ->first();

        $documentRoot = $appInstance->root ?? $appInstance->app->root;

        if (
            ! $route instanceof Route
            || $route->node_id !== $inventory->routeNodeId
            || $route->hostname !== $inventory->routeHostname
            || $route->status->value !== $inventory->routeStatus
            || $documentRoot !== $inventory->documentRoot
            || $this->routeTargetsHash($route) !== $inventory->routeTargetsHash
        ) {
            throw $this->conflict('deployment_layout.serving_conflict', 'The AppInstance serving association changed.');
        }

        return $route;
    }

    private function routeTargetsHash(Route $route): string
    {
        $targets = $route->targets
            ->map(static fn ($target): array => [
                'app_instance_id' => $target->app_instance_id,
                'position' => $target->position,
            ])
            ->values()
            ->all();

        return hash('sha256', json_encode($targets, JSON_THROW_ON_ERROR));
    }

    private function validateConvertedState(int $appInstanceId, DeploymentLayoutInventory $inventory): AppInstance
    {
        $appInstance = $this->activeProductionInstance($appInstanceId);

        if ($appInstance->checkout_path !== $inventory->releasePath || ! $appInstance->usesProductionReleaseLayout()) {
            throw $this->conflict('deployment_layout.placement_conflict', 'The recorded release placement does not agree.');
        }

        ProductionPhpRuntimeIdentity::from($appInstance);
        $route = $this->soleActiveRoute($appInstance);

        if ($route->id !== $inventory->routeId) {
            throw $this->conflict('deployment_layout.serving_conflict', 'The AppInstance serving association changed.');
        }

        $this->converter->validatePlacedLayout($appInstance, $inventory);
        $this->releaseLayout->validateCurrent($appInstance);

        return $appInstance;
    }

    private function validateCompleted(AppInstanceDeploymentLayout $record): AppInstance
    {
        if ($record->step !== DeploymentLayoutStep::Completed || $record->completed_at === null) {
            throw $this->conflict('deployment_layout.lifecycle_conflict', 'The conversion lifecycle is incomplete.');
        }

        return $this->validateConvertedState(
            $record->app_instance_id,
            DeploymentLayoutInventory::fromArray($record->inventory),
        )->load('routes.targets');
    }

    private function assertRequestIdentity(
        AppInstanceDeploymentLayout $record,
        PrepareAppInstanceDeploymentLayoutData $data,
    ): void {
        if ($record->sqlite_source_path !== $data->sqliteSourcePath) {
            throw $this->conflict(
                'deployment_layout.request_conflict',
                'The retry does not match the recorded SQLite selection.',
            );
        }
    }

    private function checkpoint(
        AppInstanceDeploymentLayout $record,
        DeploymentLayoutStep $step,
        bool $completed = false,
    ): void {
        $record->update([
            'step' => $step,
            'failed_step' => null,
            'error_code' => null,
            'completed_at' => $completed ? now() : null,
        ]);
        $record->refresh();
    }

    private function conflict(string $code, string $message): ResourceOperationException
    {
        return new ResourceOperationException($code, $message, 409);
    }
}
