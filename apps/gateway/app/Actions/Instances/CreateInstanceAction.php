<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Data\Instances\CreateInstanceData;
use App\Data\Instances\InstanceData;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Instances\DevelopmentInstanceCheckoutCopier;
use App\Domain\Instances\DevelopmentInstanceProvisioner;
use App\Domain\Instances\DevelopmentInstanceSourceLifecycle;
use App\Domain\Instances\DevelopmentSourceResolution;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\InstanceCopyMode;
use App\Domain\Instances\InstanceCreation;
use App\Domain\Instances\InstanceDestinationGuard;
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
use App\Domain\Projects\ProjectLifecycleRunner;
use App\Domain\Routes\RouteProvenance;
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
use App\Models\RouteTarget;
use Illuminate\Support\Facades\DB;
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
        private DevelopmentInstanceCheckoutCopier $copies,
        private AppDevSourceOperationLock $sourceLock,
        private DevelopmentInstanceSourceLifecycle $source,
        private DevelopmentInstanceProvisioner $provisioner,
        private IsolateCopiedInstanceAction $isolation,
        private ProductionInstanceProvisioner $productionProvisioner,
        private ?RecordEventBroadcaster $broadcaster = null,
        private ?MetricsFleetReconciler $metrics = null,
        private ?ProjectLifecycleRunner $lifecycle = null,
        private ?RemoveInstanceAction $remover = null,
        private ?InstanceEnvironmentOperationLock $environmentOperations = null,
        private ?CommandDeadline $deadline = null,
    ) {}

    /** @return array{instance: Instance, created: bool} */
    public function execute(CreateInstanceData $data): array
    {
        $project = Project::query()->findOrFail($data->projectId);
        $this->assertCompleteSourceDefaults($project);
        $requestedNode = Node::query()->findOrFail($data->nodeId);
        $root = $data->root === null ? null : ProjectRoot::validate($data->root, $project->type);

        if ($data->sourceInstanceId !== null) {
            if (
                $requestedNode
                    ->roles()
                    ->where('role', RoleName::AppProd)
                    ->where('status', LifecycleStatus::Active)
                    ->exists()
            ) {
                throw new ResourceOperationException(
                    errorCode: 'instance.candidate_required',
                    message: 'New production Instances require a candidate. Use instance:clone.',
                    status: 409,
                );
            }

            return $this->announceCreated($this->executeCopy($data, $project, $requestedNode, $root));
        }

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
                'root' => $root,
                'branch_override' => $data->branch,
                'creation' => InstanceCreation::Repository,
                'status' => InstanceState::Reserved,
            ]);
            $created = true;
        }

        $result = ($this->environmentOperations ?? app(InstanceEnvironmentOperationLock::class))->run(
            [$instance->id],
            fn (): Instance => $this->sourceLock->synchronized(
                $instance->node_id,
                function () use ($instance, $created, $data): Instance {
                    $wasActive = $instance->refresh()->status === InstanceState::Active;

                    if ($wasActive && $instance->failed_step === 'setup') {
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

                        throw $exception;
                    }

                    if (! $wasActive) {
                        $this->finishSetup($result);
                    }

                    return $result;
                },
            ),
        );

        return $this->announceCreated(['instance' => $result, 'created' => $created]);
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
                ($this->remover ?? app(RemoveInstanceAction::class))->execute($instance->fresh() ?? $instance, force: true, runTeardown: false, allowCascade: false);
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
                $this->source->prepare($instance, $allowPreparedSource);
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
            || ! is_string($project->root)
            || ! ProjectRoot::isValid($project->root, $project->type)
        ) {
            throw new ResourceOperationException(
                errorCode: 'project.source_defaults_incomplete',
                message: "App [{$project->slug}] does not have complete source defaults.",
            );
        }

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
    ): void {
        if ($instance->status === InstanceState::Removing) {
            throw $this->conflict(
                'instance.removal_conflict',
                "Instance [{$instance->name}] is being removed.",
            );
        }

        $recordedNode = Node::query()->findOrFail($instance->node_id);

        if (
            $requestedNode->id !== $recordedNode->id
            || $instance->source_layout !== InstanceSourceLayout::Checkout->value
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
        if ($instance->source_layout !== InstanceSourceLayout::Checkout->value) {
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

    /** @return array{instance: Instance, created: bool} */
    private function executeCopy(CreateInstanceData $data, Project $project, Node $node, ?string $requestedRoot): array
    {
        $source = $this->copySource($data->sourceInstanceId);
        $branch = $data->branch ?? $data->name;

        if (! GitBranchName::isValid($branch)) {
            throw $this->conflict('instance.copy_failed', 'The copy branch is invalid.');
        }

        $this->assertCopySourceLocal($source, $project, $node);
        $this->copyRoot($source, $requestedRoot);
        $existing = Instance::query()
            ->where('project_id', $project->id)
            ->where('name', $data->name)
            ->first();

        return $this->sourceLock->synchronized(
            $node->id,
            function () use ($data, $project, $node, $branch, $requestedRoot, $existing): array {
                $source = $this->copySource($data->sourceInstanceId);
                $this->assertCopySourceLocal($source, $project, $node);
                $root = $this->copyRoot($source, $requestedRoot);
                $existing = $existing instanceof Instance ? $existing->fresh() : null;

                if ($existing instanceof Instance) {
                    $this->assertRetryIdentity($existing, $node, $root, $branch);
                    $this->assertCopyIdentity($existing, $source, $data->domain);

                    if ($existing->status === InstanceState::Active && $existing->failed_step === 'setup') {
                        throw new ResourceOperationException(
                            'instance.setup_step_failed',
                            'Setup is incomplete. Run instance:setup before using this Instance.',
                            409,
                        );
                    }

                    if ($existing->status === InstanceState::Active) {
                        return ['instance' => $existing, 'created' => false];
                    }
                }

                $inspection = $existing === null || $existing->status === InstanceState::Reserved
                    ? $this->copies->inspect($source, $branch)
                    : null;

                if ($existing instanceof Instance) {
                    $instance = $existing;
                    $created = false;
                } else {
                    $this->assertPlacement($node);
                    app(DevelopmentNodeExclusion::class)->assertAvailable($project, $node);
                    $instance = $this->reserveCopyInstance($project, $node, $data->name, $root, $branch, $source->id);
                    $created = true;
                }

                $owned = false;
                $completed = ($this->environmentOperations ?? app(InstanceEnvironmentOperationLock::class))->run(
                    $this->copyLockIds($instance),
                    function () use ($instance, $data, $source, $branch, $inspection, &$owned): Instance {
                        try {
                            $this->provisioner->reserve($instance, $data->domain);
                            $resolved = $this->resumeCopy($instance, $source, $branch, $inspection?->head, $owned);
                            $result = $this->provisioner->complete($resolved, $data->domain, setupPending: true);

                            try {
                                $this->copies->deleteMarker($result);
                            } catch (Throwable) {
                                // A leftover marker names this Instance and is outside the checkout.
                            }
                        } catch (Throwable $exception) {
                            $this->recordFailure($instance, $exception);
                            $current = $instance->fresh();
                            $started = $owned
                                || ($current instanceof Instance && $current->status !== InstanceState::Reserved)
                                || ($exception instanceof ResourceOperationException && ($exception->details['copy_started'] ?? '') === '1');

                            if ($started && (! $current instanceof Instance || $current->status !== InstanceState::Active)) {
                                $this->removeFailedCopy($current ?? $instance);
                            }

                            throw $exception;
                        }

                        $this->finishSetup($result);

                        return $result->refresh();
                    },
                );

                return ['instance' => $completed, 'created' => $created];
            },
        );
    }

    private function resumeCopy(Instance $instance, Instance $source, string $branch, ?string $expectedHead, bool &$owned): Instance
    {
        while (true) {
            $instance->refresh()->loadMissing(['project', 'node']);
            $this->assertPersistedOwnership($instance);

            if ($instance->status === InstanceState::Reserved) {
                if (! is_string($expectedHead)) {
                    throw $this->conflict('instance.copy_failed', 'The source commit was not recorded.');
                }

                $copied = $this->copies->copy(
                    $source,
                    $instance,
                    $branch,
                    $expectedHead,
                    $instance->name === 'default' ? 'instance.default_path_occupied' : 'instance.path_taken',
                );
                $owned = true;

                if (
                    $copied->head !== $expectedHead
                    || ! in_array($copied->mode, [InstanceCopyMode::Reflink, InstanceCopyMode::Full], true)
                ) {
                    throw $this->conflict('instance.copy_failed', 'The copy returned invalid evidence.');
                }

                $this->isolation->execute($source, $instance);

                $this->transition($instance, InstanceState::Reserved, [
                    'branch' => $branch,
                    'starting_commit' => $copied->head,
                    'copy_mode' => $copied->mode,
                    'status' => InstanceState::CheckoutPrepared,
                ]);

                continue;
            }

            if ($instance->status === InstanceState::CheckoutPrepared) {
                $this->source->inspectPrepared($instance);
                $resolution = $this->source->inspectResolved($instance);
                $this->assertResolution($instance, $resolution);

                if ($resolution->startingCommit !== $instance->starting_commit) {
                    throw $this->conflict('instance.copy_source_changed', 'The copied HEAD does not match the source.');
                }

                $this->transition($instance, InstanceState::CheckoutPrepared, [
                    'status' => InstanceState::SourceResolved,
                ]);

                continue;
            }

            if ($instance->status === InstanceState::SourceResolved) {
                $this->source->inspectPrepared($instance);
                $this->assertStoredResolution($instance, $this->source->inspectResolved($instance));

                return $instance->refresh();
            }

            throw $this->conflict('instance.lifecycle_conflict', 'Instance lifecycle evidence changed.');
        }
    }

    private function reserveCopyInstance(
        Project $project,
        Node $node,
        string $name,
        ?string $root,
        string $branch,
        int $sourceId,
    ): Instance {
        $account = $this->accounts->resolve($node);
        $checkout = $this->storageRoots->resolveApps(
            $this->nodeSettings->fromStored($node->settings),
            $account,
        )->append($project->slug, $name);
        $this->checkoutOverlap->assertAvailable(
            $node->id,
            $checkout,
            $name === 'default' ? 'instance.default_path_occupied' : 'instance.path_taken',
        );

        return Instance::query()->create([
            'project_id' => $project->id,
            'node_id' => $node->id,
            'name' => $name,
            'source_layout' => InstanceSourceLayout::Checkout,
            'checkout_path' => $checkout->value,
            'root' => $root,
            'branch_override' => $branch,
            'creation' => InstanceCreation::Copy,
            'source_instance_id' => $sourceId,
            'status' => InstanceState::Reserved,
        ]);
    }

    private function copySource(?int $id): Instance
    {
        $source = Instance::query()->find($id);

        if (! $source instanceof Instance) {
            throw new ResourceOperationException(
                'instance.copy_source_missing',
                'The source Instance does not exist.',
                404,
            );
        }

        return $source->loadMissing(['project', 'node.roles']);
    }

    private function assertCopySourceLocal(Instance $source, Project $project, Node $node): void
    {
        if ($source->project_id !== $project->id) {
            throw $this->conflict('instance.copy_project_mismatch', 'The source Instance belongs to another Project.');
        }

        if (! $source->placedOnAppDev()) {
            throw $this->conflict('instance.copy_source_not_development', 'The source Instance is not a development Instance.');
        }

        if ($source->node_id !== $node->id) {
            throw $this->conflict('instance.copy_node_mismatch', 'The source Instance is on another Node.');
        }

        if ($source->status !== InstanceState::Active) {
            throw $this->conflict('instance.copy_source_inactive', 'The source Instance is not active.');
        }

        if ($source->source_layout !== InstanceSourceLayout::Checkout->value) {
            throw $this->conflict('instance.copy_source_layout_invalid', 'The source Instance is not an independent checkout.');
        }

        if (! is_string($source->branch) || ! GitBranchName::isValid($source->branch)) {
            throw $this->conflict('instance.copy_source_branch_invalid', 'The source Instance is not on a recorded branch.');
        }
    }

    private function copyRoot(Instance $source, ?string $requestedRoot): ?string
    {
        if ($requestedRoot !== null && $requestedRoot !== $source->root) {
            throw $this->conflict('instance.copy_root_mismatch', 'The copy root must match the source Instance.');
        }

        return $source->root;
    }

    private function assertCopyIdentity(Instance $instance, Instance $source, ?string $domain): void
    {
        if ($instance->creation !== InstanceCreation::Copy || $instance->source_instance_id !== $source->id) {
            throw $this->conflict('instance.placement_conflict', 'Instance placement is immutable.');
        }

        $route = $instance->routes()->first();

        if (! $route instanceof Route) {
            return;
        }

        if ($domain === null) {
            if ($route->provenance !== RouteProvenance::Generated) {
                throw $this->conflict('instance.placement_conflict', 'Instance placement is immutable.');
            }

            return;
        }

        if ($route->domain !== $domain) {
            throw $this->conflict('instance.placement_conflict', 'Instance placement is immutable.');
        }
    }

    /** @return list<int> */
    private function copyLockIds(Instance $instance): array
    {
        $ids = [];

        foreach (Instance::query()
            ->where('project_id', $instance->project_id)
            ->where('node_id', $instance->node_id)
            ->orderBy('id')
            ->pluck('id') as $id) {
            if (is_int($id)) {
                $ids[] = $id;
            } elseif (is_string($id) && ctype_digit($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids === [] ? [$instance->id] : $ids;
    }

    private function removeFailedCopy(Instance $instance): void
    {
        $current = $instance->fresh() ?? $instance;

        try {
            $this->copies->discardPartial($current);
        } catch (Throwable) {
            // Removal below still releases the Route and the Instance row.
        }

        try {
            ($this->remover ?? app(RemoveInstanceAction::class))->execute(
                $current->fresh() ?? $current,
                force: true,
                runTeardown: false,
                allowCascade: false,
            );
        } catch (Throwable) {
            $this->releaseCopyReservations($current);
        }
    }

    private function releaseCopyReservations(Instance $instance): void
    {
        DB::transaction(static function () use ($instance): void {
            $routeIds = RouteTarget::query()->where('instance_id', $instance->id)->pluck('route_id');
            RouteTarget::query()->where('instance_id', $instance->id)->delete();
            Route::query()->whereIn('id', $routeIds)->whereDoesntHave('targets')->delete();
            DB::table('vite_port_assignments')->where('instance_id', $instance->id)->delete();
            Instance::query()->whereKey($instance->id)->delete();
        });
    }
}
