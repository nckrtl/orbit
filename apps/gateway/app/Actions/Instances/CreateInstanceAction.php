<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\DatabaseConnections\CreateServerDatabaseAction;
use App\Data\Instances\CreateInstanceData;
use App\Data\Instances\InstanceData;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Instances\DatabaseClone\InstanceDatabaseClonePlan;
use App\Domain\Instances\DatabaseClone\InstanceDatabaseClonePlanner;
use App\Domain\Instances\DevelopmentInstanceProvisioner;
use App\Domain\Instances\DevelopmentInstanceSourceLifecycle;
use App\Domain\Instances\DevelopmentSourceResolution;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\InstanceCreationRecovery;
use App\Domain\Instances\InstanceDestinationGuard;
use App\Domain\Instances\InstanceRemovalStatus;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\ProductionInstanceProvisioner;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\ManagedCheckoutOverlap;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Nodes\Storage\StorageRootResolver;
use App\Domain\Projects\DevelopmentNodeExclusion;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\ProjectApps;
use App\Domain\Projects\ProjectLifecycleRunner;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Domain\SourceControl\ProjectRoot;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class CreateInstanceAction
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
        private InstanceDestinationGuard $destinationGuard,
        private AppDevSourceOperationLock $sourceLock,
        private DevelopmentInstanceSourceLifecycle $source,
        private DevelopmentInstanceProvisioner $provisioner,
        private ProductionInstanceProvisioner $productionProvisioner,
        private ?RecordEventBroadcaster $broadcaster = null,
        private ?MetricsFleetReconciler $metrics = null,
        private ?ProjectLifecycleRunner $lifecycle = null,
        private ?RemoveInstanceAction $remover = null,
        private ?InstanceEnvironmentOperationLock $environmentOperations = null,
        private ?CommandDeadline $deadline = null,
        private ?InstanceDatabaseClonePlanner $clonePlanner = null,
        private ?CloneInstanceDatabaseAction $databaseClone = null,
        private ?CreateInstanceServerDatabaseAction $serverDatabase = null,
    ) {}

    /** @return array{instance: Instance, created: bool} */
    public function execute(CreateInstanceData $data): array
    {
        $project = Project::query()->findOrFail($data->projectId);
        $this->assertCompleteSourceDefaults($project);
        $requestedNode = Node::query()->findOrFail($data->nodeId);
        $root = $data->root === null ? null : ProjectRoot::validate($data->root, $project->type);

        if (
            $requestedNode
                ->roles()
                ->where('role', RoleName::AppProd)
                ->where('status', LifecycleStatus::Active)
                ->exists()
        ) {
            return $this->announceCreated($this->productionProvisioner->execute($data, $project, $requestedNode, $root));
        }

        $existing = Instance::query()
            ->where('project_id', $project->id)
            ->where('name', $data->name)
            ->first();
        // A database the Gateway cannot copy refuses the request before anything changes. Only a
        // create that has not finished its first setup still gets a database.
        $clonePlan = $existing instanceof Instance && ! $existing->first_setup_pending
            ? null
            : ($this->clonePlanner ?? app(InstanceDatabaseClonePlanner::class))->plan($project, $data->name);
        $databaseServer = $this->databaseServer($data, $clonePlan, $existing);

        // An identical retry finishes the cleanup that an earlier failed create started, then creates afresh.
        if ($existing instanceof Instance && $this->hasUnfinishedCleanup($existing)) {
            $this->assertRetryIdentity($existing, $requestedNode, $root, $data->branch, finishingCleanup: true);
            try {
                $this->removeFailedCreate($existing);
            } catch (Throwable $exception) {
                throw $this->cleanupIncomplete($existing, (string) $existing->error_code, $exception);
            }
            $existing = null;
        }

        if ($existing instanceof Instance) {
            $this->assertRetryIdentity($existing, $requestedNode, $root, $data->branch);
            $instance = $existing;
            $created = false;
        } else {
            $this->assertPlacement($requestedNode);
            app(DevelopmentNodeExclusion::class)->assertAvailable($project, $requestedNode);
            $account = $this->accounts->resolve($requestedNode);
            $roots = $this->storageRoots->resolveApps(
                $this->nodeSettings->fromStored($requestedNode->settings),
                $account,
            );
            $checkout = $roots->append($project->slug, $data->name);
            $this->checkoutOverlap->assertAvailable(
                $requestedNode->id,
                $checkout,
                $data->name === 'default' ? 'instance.default_path_occupied' : 'instance.path_taken',
            );

            if ($data->name === 'default') {
                $this->assertDefaultPathUnoccupied($requestedNode, $checkout);
            }
            $instance = Instance::query()->create([
                'project_id' => $project->id,
                'node_id' => $requestedNode->id,
                'name' => $data->name,
                'source_layout' => InstanceSourceLayout::Checkout,
                'checkout_path' => $checkout->value,
                'source_prepare_id' => (string) Str::uuid(),
                'root' => $root,
                'branch_override' => $data->branch,
                'status' => InstanceState::Reserved,
                'first_setup_pending' => true,
            ]);
            $created = true;
        }

        app(MigrateAppRuntimeAction::class)->execute($requestedNode);
        try {
            $result = ($this->environmentOperations ?? app(InstanceEnvironmentOperationLock::class))->run(
                [$instance->id],
                fn (): Instance => $this->sourceLock->synchronized(
                    $instance->node_id,
                    function () use ($instance, $created, $data, $clonePlan, $databaseServer): Instance {
                        $wasActive = $instance->refresh()->status === InstanceState::Active;
                        // A create that stopped after activation left the database unfinished, so this retry
                        // finishes it and runs setup. Only the create's own marker allows that: a live Instance
                        // whose later `instance:setup` failed also has `failed_step: setup`.
                        $resumesDatabase = $wasActive
                            && $instance->first_setup_pending
                            && ($clonePlan instanceof InstanceDatabaseClonePlan || $databaseServer instanceof DatabaseServer)
                            && ! $this->databaseCloner()->isComplete($instance);

                        if ($wasActive && ($instance->failed_step === 'setup' || $instance->first_setup_pending) && ! $resumesDatabase) {
                            throw new ResourceOperationException('instance.setup_step_failed', 'Setup is incomplete. Run instance:setup before using this Instance.', 409);
                        }

                        try {
                            $this->provisioner->reserve($instance, $data->domain);
                            $resolved = $this->resumeSource($instance, ! $created);

                            $result = $this->provisioner->complete(
                                $resolved,
                                $data->domain,
                                setupPending: ! $wasActive,
                            );

                        } catch (Throwable $exception) {
                            $this->recordFailure($instance, $exception);
                            if (! $wasActive && $instance->refresh()->status !== InstanceState::Active) {
                                $this->cleanupFailedCreate($instance, $exception);
                            }

                            throw $exception;
                        }

                        if (! $wasActive || $resumesDatabase) {
                            // Only the request that activated the Instance may remove it. A retry keeps it.
                            $removeOnFailure = ! $wasActive;

                            if ($clonePlan instanceof InstanceDatabaseClonePlan) {
                                $this->prepareDatabase($result, 'database_clone', 'database copy', fn () => $this->databaseCloner()->execute($result, $clonePlan), $removeOnFailure);
                            } elseif ($databaseServer instanceof DatabaseServer) {
                                $this->prepareDatabase($result, 'database_create', 'database creation', fn () => ($this->serverDatabase ?? app(CreateInstanceServerDatabaseAction::class))->execute($result, $databaseServer), $removeOnFailure);
                            }

                            $this->finishSetup($result, $removeOnFailure);
                        }

                        return $result;
                    },
                ),
            );
        } catch (Throwable $exception) {
            $remaining = Instance::query()->find($instance->id);
            if ($created && $remaining?->status === InstanceState::Reserved && ! ($exception instanceof ResourceOperationException && ($exception->details['cleanup'] ?? null) === 'incomplete')) {
                $this->cleanupFailedCreate($remaining, $exception);
            }
            throw $exception;
        }

        return $this->announceCreated(['instance' => $result, 'created' => $created]);
    }

    private function cleanupFailedCreate(Instance $instance, Throwable $failure): void
    {
        $code = property_exists($failure, 'errorCode') && is_string($failure->errorCode)
            ? $failure->errorCode : 'instance.provisioning_failed';
        try {
            if ($instance->status === InstanceState::Reserved && $instance->source_prepare_id === null) {
                throw new ResourceOperationException('instance.source_ownership_mismatch', 'Unconfirmed source has no preparation ownership evidence.', 409);
            }
            $this->removeFailedCreate($instance);
        } catch (Throwable) {
            throw $this->cleanupIncomplete($instance, $code, $failure);
        }
    }

    /**
     * Removes a failed create without teardown or cascade. A removal that started keeps its progress,
     * so one resume can finish it after a passing failure such as a busy lock.
     */
    private function removeFailedCreate(Instance $instance): void
    {
        $remover = $this->remover ?? app(RemoveInstanceAction::class);
        try {
            $remover->execute($instance, force: true, runTeardown: false, allowCascade: false, requirePreActivation: true);
        } catch (Throwable $exception) {
            $current = Instance::query()->find($instance->id);
            if ($current?->status !== InstanceState::Removing) {
                throw $exception;
            }
            $remover->execute($current, force: true, runTeardown: false, allowCascade: false, requirePreActivation: true);
        }
    }

    /**
     * A create that failed before activation and whose own forced cleanup started and stopped before it
     * finished. Setup and database rollbacks keep their own recovery.
     */
    private function hasUnfinishedCleanup(Instance $instance): bool
    {
        if (
            $instance->status !== InstanceState::Removing
            || $instance->failed_step === null
            || in_array($instance->failed_step, ['setup', 'database_clone', 'database_create'], true)
            || $instance->error_code === null
            || ! InstanceCreationRecovery::isPreActivation($instance, removing: true)
        ) {
            return false;
        }
        $removal = InstanceRemoval::query()
            ->whereHas('members', static fn ($query) => $query->where('instance_id', $instance->id)->whereNull('row_deleted_at'))
            ->latest('created_at')
            ->first();

        return $removal instanceof InstanceRemoval && $removal->force && $removal->status === InstanceRemovalStatus::Failed;
    }

    private function cleanupIncomplete(Instance $instance, string $code, Throwable $failure): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: $code,
            message: 'Instance creation failed and cleanup is incomplete. Retry the same request to finish cleanup, or finish removal with '
                ."`orbit instance:destroy {$instance->id} --force`.",
            status: $failure instanceof ResourceOperationException ? $failure->status : 502,
            previous: $failure,
            details: [...($failure instanceof ResourceOperationException ? $failure->details : []), 'cleanup' => 'incomplete', 'instance_id' => (string) $instance->id],
        );
    }

    /**
     * The Database server named in the request, checked before anything changes. A new Instance
     * that gets a copy of the default Instance's database cannot also get an empty one. An Instance
     * that finished its first setup gets its database from `database:create --instance`, never from
     * a create retry, so a failure here can never remove it. A repeat of a finished create that
     * already made the database on that server changes nothing.
     */
    private function databaseServer(CreateInstanceData $data, ?InstanceDatabaseClonePlan $clonePlan, ?Instance $existing): ?DatabaseServer
    {
        if ($data->databaseServer === null) {
            return null;
        }

        if ($existing instanceof Instance && ! $existing->first_setup_pending) {
            $owned = DatabaseConnection::query()
                ->where('owner_instance_id', $existing->id)
                ->whereHas('server', static fn ($query) => $query->where('slug', $data->databaseServer))
                ->exists();

            if ($owned) {
                return null;
            }

            $slug = $this->databaseCloner()->slug($existing);

            throw new ResourceOperationException(
                errorCode: 'instance.database_server_existing',
                message: "Instance [{$existing->name}] already exists, so instance:create does not create its database. "
                    ."Run `orbit database:create {$slug} --server={$data->databaseServer} --instance={$existing->id}`, then `orbit instance:setup {$existing->id}`.",
                status: 409,
            );
        }

        if ($clonePlan instanceof InstanceDatabaseClonePlan) {
            throw new ResourceOperationException(
                errorCode: 'instance.database_server_conflict',
                message: 'The new Instance gets a copy of the default Instance\'s database. Omit database_server.',
                status: 422,
            );
        }

        return app(CreateServerDatabaseAction::class)->activeServer($data->databaseServer);
    }

    /**
     * Give the Instance its database before the setup steps, so a migration runs against it: a
     * copy of the default Instance's database, or an empty one on the requested Database server.
     * On the request that activated the Instance, a failure removes it as a failed setup does. A
     * retry keeps it.
     *
     * @param  Closure(): mixed  $prepare
     */
    private function prepareDatabase(Instance $instance, string $step, string $operation, Closure $prepare, bool $removeOnFailure): void
    {
        try {
            $prepare();
        } catch (ResourceOperationException $failure) {
            $instance->update(['failed_step' => $step, 'error_code' => $failure->errorCode]);

            if (! $removeOnFailure) {
                throw new ResourceOperationException(
                    errorCode: $failure->errorCode,
                    message: $failure->getMessage().' The Instance remains. Repeat instance:create to try again.',
                    status: $failure->status,
                    previous: $failure,
                    details: $failure->details,
                );
            }

            try {
                ($this->remover ?? app(RemoveInstanceAction::class))->execute($instance->fresh() ?? $instance, force: true, runTeardown: false, allowCascade: false);
            } catch (Throwable) {
                throw new ResourceOperationException(
                    errorCode: $failure->errorCode,
                    message: "The {$operation} failed and cleanup is incomplete. Inspect the Instance, then finish the removal with "
                        ."`orbit instance:destroy {$instance->id} --force`.",
                    status: $failure->status,
                    previous: $failure,
                    details: ['cleanup' => 'incomplete'],
                );
            }

            throw new ResourceOperationException(
                errorCode: $failure->errorCode,
                message: $failure->getMessage().' The Instance was removed.',
                status: $failure->status,
                previous: $failure,
            );
        }
    }

    private function databaseCloner(): CloneInstanceDatabaseAction
    {
        return $this->databaseClone ?? app(CloneInstanceDatabaseAction::class);
    }

    private function finishSetup(Instance $instance, bool $removeOnFailure): void
    {
        $runner = $this->lifecycle ?? app(ProjectLifecycleRunner::class);

        $deadline = $this->deadline ?? app(CommandDeadline::class);

        try {
            // A failed setup must still leave time to tear down and remove the Instance it created.
            $deadline->holding(
                self::RollbackTeardownSeconds + self::RollbackRemovalSeconds,
                fn (): bool => $runner->run($instance, LifecyclePhase::Setup),
            );
            $instance->update(['failed_step' => null, 'error_code' => null, 'first_setup_pending' => false]);
        } catch (ResourceOperationException $setupFailure) {
            if (($setupFailure->details['outcome'] ?? null) === 'busy') {
                $instance->update(['failed_step' => 'setup', 'error_code' => 'instance.lifecycle_busy']);

                throw $setupFailure;
            }

            $details = $setupFailure->details;
            // Deadline cuts and unavailable commands keep their classification through rollback.
            $deadlineCut = $setupFailure->errorCode === 'command.deadline_exceeded';
            $unavailable = $setupFailure->errorCode === 'instance.setup_step_unavailable';
            $code = $deadlineCut || $unavailable ? $setupFailure->errorCode : 'instance.setup_step_failed';
            $status = $deadlineCut ? 504 : 422;
            $cause = $deadlineCut ? 'Setup ran out of the request deadline' : 'Setup failed';
            $diagnostic = $unavailable ? $setupFailure->getMessage().' ' : '';
            $instance->update(['failed_step' => 'setup', 'error_code' => $code]);

            if (($details['outcome'] ?? null) === 'unconfirmed') {
                throw $setupFailure;
            }

            if (! $removeOnFailure) {
                throw new ResourceOperationException(
                    errorCode: $code,
                    message: "{$diagnostic}{$cause}. The Instance remains. Fix the step, then run `orbit instance:setup {$instance->id}`.",
                    status: $status,
                    previous: $setupFailure,
                    details: $details,
                );
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
                        message: "{$diagnostic}{$cause} and teardown could not be confirmed. The Instance remains.",
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
                ($this->remover ?? app(RemoveInstanceAction::class))->execute($instance->fresh() ?? $instance, force: true, runTeardown: false, allowCascade: false);
            } catch (Throwable) {
                throw new ResourceOperationException(
                    errorCode: $code,
                    message: "{$diagnostic}{$cause} and cleanup is incomplete. Inspect the Instance, then finish the removal with "
                        ."`orbit instance:destroy {$instance->id} --force`.",
                    status: $status,
                    details: [...$details, 'cleanup' => 'incomplete'],
                );
            }

            throw new ResourceOperationException(
                errorCode: $code,
                message: $deadlineCut || $unavailable ? $setupFailure->getMessage().' The Instance was removed.' : 'Setup step failed.',
                status: $status,
                previous: $setupFailure,
                details: $details,
            );
        }
    }

    /**
     * @param  array{instance: Instance, created: bool}  $result
     * @return array{instance: Instance, created: bool}
     */
    private function announceCreated(array $result): array
    {
        if ($result['instance']->placedOnAppProd()) {
            $this->metrics?->reconcile();
        }
        if ($result['created']) {
            ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
                RecordEventType::InstanceCreated,
                $result['instance']->id,
                InstanceData::fromModel($result['instance'])->toArray(),
            );
        }

        return $result;
    }

    private function resumeSource(Instance $instance, bool $allowPreparedSource): Instance
    {
        while (true) {
            $instance->refresh()->loadMissing(['project', 'node']);
            $this->assertPersistedOwnership($instance);

            if ($instance->status === InstanceState::Reserved) {
                $this->source->prepare($instance, $allowPreparedSource && $instance->source_prepare_id !== null);
                $this->transition($instance, InstanceState::Reserved, [
                    'status' => InstanceState::CheckoutPrepared,
                ]);

                continue;
            }

            if ($instance->status === InstanceState::CheckoutPrepared) {
                $this->source->inspectPrepared($instance);
                $resolution = $this->source->resolve($instance);
                $this->assertResolution($instance, $resolution);
                $this->transition($instance, InstanceState::CheckoutPrepared, [
                    'branch' => $resolution->branch,
                    'starting_commit' => $resolution->startingCommit,
                    'status' => InstanceState::SourceResolved,
                ]);

                continue;
            }

            if ($instance->status === InstanceState::SourceResolved) {
                $this->source->inspectPrepared($instance);
                $this->assertStoredResolution($instance, $this->source->inspectResolved($instance));

                return $instance->refresh();
            }

            $this->assertStoredResolutionEvidence($instance);
            $this->source->inspectPrepared($instance);

            return $instance->refresh();
        }
    }

    /** @param array<string, mixed> $attributes */
    private function transition(Instance $instance, InstanceState $from, array $attributes): void
    {
        DB::transaction(function () use ($instance, $from, $attributes): void {
            $locked = Instance::query()->lockForUpdate()->findOrFail($instance->id);

            if ($locked->status !== $from) {
                throw $this->conflict('instance.lifecycle_conflict', 'Instance lifecycle evidence changed.');
            }

            $locked->update($attributes);
        });
    }

    private function assertCompleteSourceDefaults(Project $project): void
    {
        if (
            ! is_string($project->default_branch)
            || ! GitBranchName::isValid($project->default_branch)

        ) {
            throw new ResourceOperationException(
                errorCode: 'project.source_defaults_incomplete',
                message: "App [{$project->slug}] does not have complete source defaults.",
            );
        }

        ProjectApps::validate($project->configuredApps());
        GitRepositoryOrigin::validate($project->repository_url);
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
        Instance $instance,
        Node $requestedNode,
        ?string $root,
        ?string $branchOverride,
        bool $finishingCleanup = false,
    ): void {
        if ($instance->status === InstanceState::Removing && ! $finishingCleanup) {
            throw $this->conflict(
                'instance.removal_conflict',
                "Instance [{$instance->name}] is being removed.",
            );
        }

        $recordedNode = Node::query()->findOrFail($instance->node_id);

        if (
            $requestedNode->id !== $recordedNode->id
            || ! ($instance->source_layout === InstanceSourceLayout::Checkout->value || ($instance->source_layout === InstanceSourceLayout::Worktree->value && $instance->seed_repository !== null))
            || $instance->root !== $root
            || $instance->branch_override !== $branchOverride
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

    private function assertPersistedOwnership(Instance $instance): void
    {
        if (! ($instance->source_layout === InstanceSourceLayout::Checkout->value || ($instance->source_layout === InstanceSourceLayout::Worktree->value && $instance->seed_repository !== null))) {
            throw $this->conflict('instance.source_layout_conflict', 'Instance source ownership is invalid.');
        }
    }

    private function assertResolution(
        Instance $instance,
        DevelopmentSourceResolution $resolution,
    ): void {
        if (
            $resolution->branch !== $this->expectedBranch($instance)
            || preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $resolution->startingCommit) !== 1
        ) {
            throw $this->conflict('instance.source_identity_invalid', 'Resolved source identity is invalid.');
        }
    }

    private function assertStoredResolution(
        Instance $instance,
        DevelopmentSourceResolution $resolution,
    ): void {
        $this->assertStoredResolutionEvidence($instance);
        $this->assertResolution($instance, $resolution);

        if (
            $instance->branch !== $resolution->branch
            || $instance->starting_commit !== $resolution->startingCommit
        ) {
            throw $this->conflict('instance.source_identity_changed', 'Instance source identity changed.');
        }
    }

    private function assertStoredResolutionEvidence(Instance $instance): void
    {
        if (
            $instance->branch !== $this->expectedBranch($instance)
            || ! is_string($instance->starting_commit)
            || preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $instance->starting_commit) !== 1
        ) {
            throw $this->conflict('instance.source_identity_changed', 'Instance source identity changed.');
        }
    }

    private function expectedBranch(Instance $instance): string
    {
        if (is_string($instance->branch_override)) {
            return $instance->branch_override;
        }

        if ($instance->name === 'default') {
            return (string) $instance->project->default_branch;
        }

        return $instance->name;
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
    private function recordFailure(Instance $instance, Throwable $exception): void
    {
        $instance->refresh();

        if ($instance->status === InstanceState::Active) {
            return;
        }

        $step = property_exists($exception, 'step') && is_string($exception->step)
            ? $exception->step
            : match ($instance->status) {
                InstanceState::Reserved => 'source-prepare',
                InstanceState::CheckoutPrepared => 'source-resolve',
                default => 'provisioning',
            };
        $errorCode = property_exists($exception, 'errorCode') && is_string($exception->errorCode)
            ? $exception->errorCode
            : 'instance.provisioning_failed';

        DB::transaction(static function () use ($instance, $step, $errorCode): void {
            Instance::query()
                ->whereKey($instance->id)
                ->update([
                    'failed_step' => $step,
                    'error_code' => $errorCode,
                ]);
            Route::query()
                ->whereHas('targets', static fn ($query) => $query->where('instance_id', $instance->id))
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
