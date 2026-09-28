<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Data\AppInstances\AppInstanceData;
use App\Data\AppInstances\CreateAppInstanceData;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppInstances\AppInstanceDestinationGuard;
use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\DevelopmentAppInstanceProvisioner;
use App\Domain\AppInstances\DevelopmentAppInstanceSourceLifecycle;
use App\Domain\AppInstances\DevelopmentSourceResolution;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\AppInstances\ProductionAppInstanceProvisioner;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\ManagedCheckoutOverlap;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Nodes\Storage\StorageRootResolver;
use App\Domain\Projects\DevelopmentNodeExclusion;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\ProjectLifecycleRunner;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Domain\SourceControl\ProjectRoot;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class CreateAppInstanceAction
{
    /**
     * The time a create keeps back from its setup list for the rollback of a failed setup: the
     * teardown list gets up to this many seconds, and the removal of the new Instance the next.
     */
    public const float RollbackTeardownSeconds = 60.0;

    public const float RollbackRemovalSeconds = 90.0;

    public function __construct(
        private ManagedUserAccountResolver $accounts,
        private StorageRootResolver $storageRoots,
        private NodeSettingsNormalizer $nodeSettings,
        private ManagedCheckoutOverlap $checkoutOverlap,
        private AppInstanceDestinationGuard $destinationGuard,
        private AppDevSourceOperationLock $sourceLock,
        private DevelopmentAppInstanceSourceLifecycle $source,
        private DevelopmentAppInstanceProvisioner $provisioner,
        private ProductionAppInstanceProvisioner $productionProvisioner,
        private ?RecordEventBroadcaster $broadcaster = null,
        private ?MetricsFleetReconciler $metrics = null,
        private ?ProjectLifecycleRunner $lifecycle = null,
        private ?RemoveAppInstanceAction $remover = null,
        private ?AppInstanceEnvironmentOperationLock $environmentOperations = null,
        private ?CommandDeadline $deadline = null,
    ) {}

    /** @return array{appInstance: Instance, created: bool} */
    public function execute(CreateAppInstanceData $data): array
    {
        $app = Project::query()->findOrFail($data->appId);
        $this->assertCompleteSourceDefaults($app);
        $requestedNode = Node::query()->findOrFail($data->nodeId);
        $root = $data->root === null ? null : ProjectRoot::validate($data->root, $app->type);

        if (
            $requestedNode
                ->roles()
                ->where('role', RoleName::AppProd)
                ->where('status', LifecycleStatus::Active)
                ->exists()
        ) {
            return $this->announceCreated($this->productionProvisioner->execute($data, $app, $requestedNode, $root));
        }

        $existing = Instance::query()
            ->where('project_id', $app->id)
            ->where('name', $data->name)
            ->first();

        if ($existing instanceof Instance) {
            $this->assertRetryIdentity($existing, $requestedNode, $root, $data->branch);
            $appInstance = $existing;
            $created = false;
        } else {
            $this->assertPlacement($requestedNode);
            app(DevelopmentNodeExclusion::class)->assertAvailable($app, $requestedNode);
            $account = $this->accounts->resolve($requestedNode);
            $roots = $this->storageRoots->resolveApps(
                $this->nodeSettings->fromStored($requestedNode->settings),
                $account,
            );
            $checkout = $roots->append($app->slug, $data->name);
            $this->checkoutOverlap->assertAvailable(
                $requestedNode->id,
                $checkout,
                $data->name === 'default' ? 'instance.default_path_occupied' : 'instance.path_taken',
            );

            if ($data->name === 'default') {
                $this->assertDefaultPathUnoccupied($requestedNode, $checkout);
            }
            $appInstance = Instance::query()->create([
                'project_id' => $app->id,
                'node_id' => $requestedNode->id,
                'name' => $data->name,
                'source_layout' => AppInstanceSourceLayout::Checkout,
                'checkout_path' => $checkout->value,
                'root' => $root,
                'branch_override' => $data->branch,
                'status' => AppInstanceState::Reserved,
            ]);
            $created = true;
        }

        $result = ($this->environmentOperations ?? app(AppInstanceEnvironmentOperationLock::class))->run(
            [$appInstance->id],
            fn (): Instance => $this->sourceLock->synchronized(
                $appInstance->node_id,
                function () use ($appInstance, $created, $data): Instance {
                    $wasActive = $appInstance->refresh()->status === AppInstanceState::Active;

                    if ($wasActive && $appInstance->failed_step === 'setup') {
                        throw new ResourceOperationException('instance.setup_step_failed', 'Setup is incomplete. Run instance:setup before using this Instance.', 409);
                    }

                    try {
                        $this->provisioner->reserve($appInstance, $data->domain);
                        $resolved = $this->resumeSource($appInstance, ! $created);

                        $result = $this->provisioner->complete(
                            $resolved,
                            $data->domain,
                            setupPending: ! $wasActive,
                        );

                    } catch (Throwable $exception) {
                        $this->recordFailure($appInstance, $exception);

                        throw $exception;
                    }

                    if (! $wasActive) {
                        $this->finishSetup($result);
                    }

                    return $result;
                },
            ),
        );

        return $this->announceCreated(['appInstance' => $result, 'created' => $created]);
    }

    private function finishSetup(Instance $instance): void
    {
        $runner = $this->lifecycle ?? app(ProjectLifecycleRunner::class);

        $deadline = $this->deadline ?? app(CommandDeadline::class);

        try {
            // A failed setup must still leave time to tear down and remove the Instance it created.
            $deadline->holding(
                self::RollbackTeardownSeconds + self::RollbackRemovalSeconds,
                fn (): bool => $runner->run($instance, LifecyclePhase::Setup),
            );
            $instance->update(['failed_step' => null, 'error_code' => null]);
        } catch (ResourceOperationException $setupFailure) {
            if (($setupFailure->details['outcome'] ?? null) === 'busy') {
                $instance->update(['failed_step' => 'setup', 'error_code' => 'instance.lifecycle_busy']);

                throw $setupFailure;
            }

            $details = $setupFailure->details;
            // A step the request deadline stopped keeps that code, so it reads apart from a failed command.
            $deadlineCut = $setupFailure->errorCode === 'command.deadline_exceeded';
            $code = $deadlineCut ? 'command.deadline_exceeded' : 'instance.setup_step_failed';
            $status = $deadlineCut ? 504 : 422;
            $cause = $deadlineCut ? 'Setup ran out of the request deadline' : 'Setup failed';
            $instance->update(['failed_step' => 'setup', 'error_code' => $code]);

            if (($details['outcome'] ?? null) === 'unconfirmed') {
                throw $setupFailure;
            }

            try {
                $deadline->holding(
                    self::RollbackRemovalSeconds,
                    fn (): bool => $runner->run($instance->fresh() ?? $instance, LifecyclePhase::Teardown),
                );
            } catch (ResourceOperationException $teardownFailure) {
                if (($teardownFailure->details['outcome'] ?? null) === 'busy') {
                    throw $teardownFailure;
                }

                if (($teardownFailure->details['outcome'] ?? null) === 'unconfirmed') {
                    throw new ResourceOperationException(
                        errorCode: $code,
                        message: "{$cause} and teardown could not be confirmed. The Instance remains.",
                        status: $status,
                        details: [...$details, 'cleanup' => 'unconfirmed'],
                    );
                }

                $step = $teardownFailure->details['step'] ?? null;

                if (is_string($step) && $step !== '') {
                    $details['teardown_step'] = $step;
                }
            }

            try {
                ($this->remover ?? app(RemoveAppInstanceAction::class))->execute($instance->fresh() ?? $instance, force: true, runTeardown: false, allowCascade: false);
            } catch (Throwable) {
                throw new ResourceOperationException(
                    errorCode: $code,
                    message: "{$cause} and cleanup is incomplete. Inspect the Instance, then finish the removal with "
                        ."`orbit instance:destroy {$instance->id} --force`.",
                    status: $status,
                    details: [...$details, 'cleanup' => 'incomplete'],
                );
            }

            throw new ResourceOperationException(
                errorCode: $code,
                message: $deadlineCut ? $setupFailure->getMessage().' The Instance was removed.' : 'Setup step failed.',
                status: $status,
                previous: $setupFailure,
                details: $details,
            );
        }
    }

    /**
     * @param  array{appInstance: Instance, created: bool}  $result
     * @return array{appInstance: Instance, created: bool}
     */
    private function announceCreated(array $result): array
    {
        if ($result['appInstance']->placedOnAppProd()) {
            $this->metrics?->reconcile();
        }
        if ($result['created']) {
            ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
                RecordEventType::InstanceCreated,
                $result['appInstance']->id,
                AppInstanceData::fromModel($result['appInstance'])->toArray(),
            );
        }

        return $result;
    }

    private function resumeSource(Instance $appInstance, bool $allowPreparedSource): Instance
    {
        while (true) {
            $appInstance->refresh()->loadMissing(['app', 'node']);
            $this->assertPersistedOwnership($appInstance);

            if ($appInstance->status === AppInstanceState::Reserved) {
                $this->source->prepare($appInstance, $allowPreparedSource);
                $this->transition($appInstance, AppInstanceState::Reserved, [
                    'status' => AppInstanceState::CheckoutPrepared,
                ]);

                continue;
            }

            if ($appInstance->status === AppInstanceState::CheckoutPrepared) {
                $this->source->inspectPrepared($appInstance);
                $resolution = $this->source->resolve($appInstance);
                $this->assertResolution($appInstance, $resolution);
                $this->transition($appInstance, AppInstanceState::CheckoutPrepared, [
                    'branch' => $resolution->branch,
                    'starting_commit' => $resolution->startingCommit,
                    'status' => AppInstanceState::SourceResolved,
                ]);

                continue;
            }

            if ($appInstance->status === AppInstanceState::SourceResolved) {
                $this->source->inspectPrepared($appInstance);
                $this->assertStoredResolution($appInstance, $this->source->inspectResolved($appInstance));

                return $appInstance->refresh();
            }

            $this->assertStoredResolutionEvidence($appInstance);
            $this->source->inspectPrepared($appInstance);

            return $appInstance->refresh();
        }
    }

    /** @param array<string, mixed> $attributes */
    private function transition(Instance $appInstance, AppInstanceState $from, array $attributes): void
    {
        DB::transaction(function () use ($appInstance, $from, $attributes): void {
            $locked = Instance::query()->lockForUpdate()->findOrFail($appInstance->id);

            if ($locked->status !== $from) {
                throw $this->conflict('instance.lifecycle_conflict', 'Instance lifecycle evidence changed.');
            }

            $locked->update($attributes);
        });
    }

    private function assertCompleteSourceDefaults(Project $app): void
    {
        if (
            ! is_string($app->default_branch)
            || ! GitBranchName::isValid($app->default_branch)
            || ! is_string($app->root)
            || ! ProjectRoot::isValid($app->root, $app->type)
        ) {
            throw new ResourceOperationException(
                errorCode: 'app.source_defaults_incomplete',
                message: "App [{$app->slug}] does not have complete source defaults.",
            );
        }

        GitRepositoryOrigin::validate($app->repository_url);
    }

    private function assertPlacement(Node $node): void
    {
        if ($node->status !== LifecycleStatus::Active || $node->platform !== 'linux') {
            throw new ResourceOperationException('instance.node_inactive', 'The selected app-dev Node is not active.');
        }

        if (! $node->roles()->where('role', RoleName::AppDev)->where('status', LifecycleStatus::Active)->exists()) {
            throw new ResourceOperationException(
                'instance.node_not_app_dev',
                'The selected Node has no active app-dev role.',
            );
        }
    }

    private function assertRetryIdentity(
        Instance $appInstance,
        Node $requestedNode,
        ?string $root,
        ?string $branchOverride,
    ): void {
        if ($appInstance->status === AppInstanceState::Removing) {
            throw $this->conflict(
                'instance.removal_conflict',
                "Instance [{$appInstance->name}] is being removed.",
            );
        }

        $recordedNode = Node::query()->findOrFail($appInstance->node_id);

        if (
            $requestedNode->id !== $recordedNode->id
            || $appInstance->source_layout !== AppInstanceSourceLayout::Checkout->value
            || $appInstance->root !== $root
            || $appInstance->branch_override !== $branchOverride
        ) {
            throw $this->conflict('instance.placement_conflict', 'Instance placement is immutable.');
        }

        $this->assertPlacement($recordedNode);
    }

    private function assertDefaultPathUnoccupied(Node $node, StoragePath $checkout): void
    {
        try {
            $this->destinationGuard->assertUnoccupied($node, $checkout);
        } catch (ResourceOperationException $exception) {
            if ($exception->errorCode !== 'instance.migration_conflict') {
                throw $exception;
            }

            throw new ResourceOperationException(
                'instance.default_path_occupied',
                $exception->getMessage(),
                $exception->status,
                $exception,
            );
        }
    }

    private function assertPersistedOwnership(Instance $appInstance): void
    {
        if ($appInstance->source_layout !== AppInstanceSourceLayout::Checkout->value) {
            throw $this->conflict('instance.source_layout_conflict', 'Instance source ownership is invalid.');
        }
    }

    private function assertResolution(
        Instance $appInstance,
        DevelopmentSourceResolution $resolution,
    ): void {
        if (
            $resolution->branch !== $this->expectedBranch($appInstance)
            || preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $resolution->startingCommit) !== 1
        ) {
            throw $this->conflict('instance.source_identity_invalid', 'Resolved source identity is invalid.');
        }
    }

    private function assertStoredResolution(
        Instance $appInstance,
        DevelopmentSourceResolution $resolution,
    ): void {
        $this->assertStoredResolutionEvidence($appInstance);
        $this->assertResolution($appInstance, $resolution);

        if (
            $appInstance->branch !== $resolution->branch
            || $appInstance->starting_commit !== $resolution->startingCommit
        ) {
            throw $this->conflict('instance.source_identity_changed', 'Instance source identity changed.');
        }
    }

    private function assertStoredResolutionEvidence(Instance $appInstance): void
    {
        if (
            $appInstance->branch !== $this->expectedBranch($appInstance)
            || ! is_string($appInstance->starting_commit)
            || preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $appInstance->starting_commit) !== 1
        ) {
            throw $this->conflict('instance.source_identity_changed', 'Instance source identity changed.');
        }
    }

    private function expectedBranch(Instance $appInstance): string
    {
        if (is_string($appInstance->branch_override)) {
            return $appInstance->branch_override;
        }

        if ($appInstance->name === 'default') {
            return (string) $appInstance->app->default_branch;
        }

        return $appInstance->name;
    }

    private function conflict(string $errorCode, string $message): ResourceOperationException
    {
        return new ResourceOperationException($errorCode, $message, 409);
    }

    /**
     * Records failure evidence on a non-active Instance and its non-active
     * Route. An active Instance is terminal for creation retry, so a refused
     * retry leaves its row untouched.
     */
    private function recordFailure(Instance $appInstance, Throwable $exception): void
    {
        $appInstance->refresh();

        if ($appInstance->status === AppInstanceState::Active) {
            return;
        }

        $step = property_exists($exception, 'step') && is_string($exception->step)
            ? $exception->step
            : match ($appInstance->status) {
                AppInstanceState::Reserved => 'source-prepare',
                AppInstanceState::CheckoutPrepared => 'source-resolve',
                default => 'provisioning',
            };
        $errorCode = property_exists($exception, 'errorCode') && is_string($exception->errorCode)
            ? $exception->errorCode
            : 'instance.provisioning_failed';

        DB::transaction(static function () use ($appInstance, $step, $errorCode): void {
            Instance::query()
                ->whereKey($appInstance->id)
                ->update([
                    'failed_step' => $step,
                    'error_code' => $errorCode,
                ]);
            Route::query()
                ->whereHas('targets', static fn ($query) => $query->where('instance_id', $appInstance->id))
                ->where('status', '<>', RouteStatus::Active->value)
                ->update([
                    'sites_published' => false,
                    'status' => RouteStatus::Failed,
                    'failed_step' => $step,
                    'error_code' => $errorCode,
                ]);
        });
    }
}
