<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Actions\Instances\IsolateCopiedInstanceAction;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\DevelopmentInstanceCheckoutCopier;
use App\Domain\Instances\DevelopmentInstanceCopyResult;
use App\Domain\Instances\DevelopmentInstanceProvisioner;
use App\Domain\Instances\DevelopmentInstanceSourceLifecycle;
use App\Domain\Instances\DevelopmentSourceResolution;
use App\Domain\Instances\InstanceCopyMode;
use App\Domain\Instances\InstanceCreation;
use App\Domain\Instances\InstanceDestinationGuard;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\ManagedCheckoutOverlap;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Nodes\Storage\StorageRootResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Domain\SourceControl\ProjectRoot;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\InstanceProvisioning;
use App\Domain\Tasks\InstanceProvisionIntent;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskCeilings;
use App\Domain\Tasks\TaskConcurrencyGuard;
use App\Domain\Tasks\TaskWorkspaceName;
use App\Models\DatabaseConnectionTarget;
use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final readonly class TaskWorkspaceProvisioner implements InstanceProvisioning
{
    public const string SourceUnavailableReason = 'tasks.workspace_source_unavailable';

    public function __construct(
        private ManagedUserAccountResolver $accounts,
        private StorageRootResolver $storageRoots,
        private NodeSettingsNormalizer $nodeSettings,
        private ManagedCheckoutOverlap $checkoutOverlap,
        private InstanceDestinationGuard $destinationGuard,
        private AppDevSourceOperationLock $sourceLock,
        private DevelopmentInstanceSourceLifecycle $source,
        private DevelopmentInstanceProvisioner $development,
        private DevelopmentInstanceCheckoutCopier $copies,
        private IsolateCopiedInstanceAction $isolation,
        private TaskConcurrencyGuard $ceilings,
        private AgentDriverRegistry $drivers,
    ) {}

    public static function copyFailedReason(string $errorCode): string
    {
        return 'tasks.workspace_copy_failed: '.$errorCode;
    }

    public function provision(InstanceProvisionIntent $intent): ?Instance
    {
        $group = $intent->group->loadMissing(['project', 'taskable']);
        $existing = $group->taskable;

        if ($existing instanceof Instance) {
            return $existing;
        }

        $workspace = $this->existingWorkspace($group);
        $visitable = $this->routingForClaim($workspace, $intent->visitable);

        if (! $this->hasSourceDefaults($group->project, $visitable)) {
            return null;
        }

        $node = $this->selectNode($group->project, [$intent->group->implementer_agent_driver, $intent->group->reviewer_agent_driver], $this->existingWorkspaceNodeId($workspace));

        if (! $node instanceof Node) {
            return null;
        }

        try {
            return $this->createWorkspace($group, $node, $visitable);
        } catch (ResourceOperationException|RuntimeConvergenceException) {
            return null;
        }
    }

    private function createWorkspace(Task $group, Node $node, bool $visitable): Instance
    {
        $name = TaskWorkspaceName::for($group);
        $existing = Instance::query()
            ->where('project_id', $group->project_id)
            ->where('name', $name)
            ->first();

        if ($existing instanceof Instance) {
            // Only the group's own workspace carries its task branch. Another Instance with the name is never adopted.
            // A finished leftover workspace is resumed in place. A reserved copy that never finished is discarded and retried.
            if ($existing->branch_override !== $name) {
                throw new ResourceOperationException(
                    'instance.name_taken',
                    "Instance [{$name}] exists without the task branch and is not this group's workspace.",
                    409,
                );
            }

            if ($existing->node_id !== $node->id) {
                throw new ResourceOperationException(
                    'instance.placement_conflict',
                    'Instance placement is immutable.',
                    409,
                );
            }

            $visitable = $this->recordedRouting($existing, $visitable);

            return $this->sourceLock->synchronized(
                $existing->node_id,
                function () use ($existing, $group, $visitable): Instance {
                    if ($existing->status === InstanceState::Reserved && $existing->creation === InstanceCreation::Copy) {
                        return $this->resumeInterruptedCopy($group, $existing, $visitable);
                    }

                    try {
                        $instance = $this->finishFresh($existing, $visitable);
                    } catch (ResourceOperationException|RuntimeConvergenceException $exception) {
                        if (! $this->copiedCheckoutNeedsRebuild($existing)) {
                            throw $exception;
                        }

                        return $this->rebuildMissingCopiedCheckout($group, $existing, $visitable, $exception);
                    }

                    $this->rememberWorkspace($group, $instance);

                    return $instance;
                },
            );
        }

        $account = $this->accounts->resolve($node);
        $checkout = $this->storageRoots->resolveApps(
            $this->nodeSettings->fromStored($node->settings),
            $account,
        )->append($group->project->slug, $name);
        $this->checkoutOverlap->assertAvailable($node->id, $checkout, 'instance.path_taken');
        $this->destinationGuard->assertUnoccupied($node, $checkout);

        return $this->sourceLock->synchronized(
            $node->id,
            function () use ($group, $node, $visitable, $checkout, $name): Instance {
                $copied = $this->copyWorkspace($group, $node, $visitable, $checkout, $name);

                if ($copied instanceof Instance) {
                    return $copied;
                }

                $instance = $this->reserveWorkspace($group, $node, $checkout, $name, $visitable, null);

                return $this->finishFresh($instance, $visitable);
            },
        );
    }

    private function finishFresh(Instance $instance, bool $visitable): Instance
    {
        $resolved = $this->prepareSource($instance);

        return $this->activateDevelopment($resolved, $visitable);
    }

    private function activateDevelopment(Instance $instance, bool $visitable): Instance
    {
        if (! $visitable) {
            return $instance;
        }

        $this->development->reserve($instance, null);

        return $this->development->complete($instance, null);
    }

    /**
     * Copy the Project's `default` Instance when it is an eligible source on the selected Node.
     * A missing or ineligible source, and a copy that fails before the workspace exists, returns
     * null so the caller can clone a fresh checkout. The Node was already chosen.
     */
    private function copyWorkspace(Task $group, Node $node, bool $visitable, StoragePath $checkout, string $name): ?Instance
    {
        $source = $this->eligibleSource($group, $node);

        if (! $source instanceof Instance) {
            $this->recordWorkspace($group, InstanceCreation::Repository, null, self::SourceUnavailableReason);

            return null;
        }

        $branch = $source->branch;
        $defaultBranch = $group->project->default_branch;

        if (! is_string($branch) || ! is_string($defaultBranch)) {
            $this->recordWorkspace($group, InstanceCreation::Repository, null, self::SourceUnavailableReason);

            return null;
        }

        try {
            $inspection = $this->copies->inspect($source, $branch);
        } catch (ResourceOperationException) {
            $this->recordWorkspace($group, InstanceCreation::Repository, null, self::SourceUnavailableReason);

            return null;
        }

        $instance = $this->reserveWorkspace($group, $node, $checkout, $name, $visitable, $source);

        try {
            return $this->attemptCopy($group, $instance, $source, $visitable, $name, $defaultBranch, $inspection->head);
        } catch (TaskWorkspaceCopyFallback $fallback) {
            $this->deleteWorkspaceRow($instance);
            $this->recordWorkspace($group, InstanceCreation::Repository, null, self::copyFailedReason($fallback->errorCode));

            return null;
        }
    }

    /**
     * A reserved copy that stopped before the workspace existed still owns its partial tree.
     * Remove that tree, then retry the copy or fall back to a fresh clone on the same row.
     */
    private function resumeInterruptedCopy(Task $group, Instance $instance, bool $visitable): Instance
    {
        if (! $this->discardOwnedCopy($instance)) {
            $this->recordWorkspace($group, InstanceCreation::Copy, null, self::copyFailedReason('instance.copy_failed'));

            throw new ResourceOperationException(
                'instance.copy_failed',
                'The owned partial checkout could not be removed.',
                409,
            );
        }

        $instance->loadMissing('node');
        $source = $this->eligibleSource($group, $instance->node);
        $branch = $source?->branch;
        $defaultBranch = $group->project->default_branch;

        if (! $source instanceof Instance || ! is_string($branch) || ! is_string($defaultBranch)) {
            return $this->fallBackOnReservedRow($group, $instance, $visitable, self::SourceUnavailableReason);
        }

        try {
            $inspection = $this->copies->inspect($source, $branch);
        } catch (ResourceOperationException) {
            return $this->fallBackOnReservedRow($group, $instance, $visitable, self::SourceUnavailableReason);
        }

        try {
            return $this->attemptCopy($group, $instance, $source, $visitable, $instance->name, $defaultBranch, $inspection->head);
        } catch (TaskWorkspaceCopyFallback $fallback) {
            return $this->fallBackOnReservedRow($group, $instance, $visitable, self::copyFailedReason($fallback->errorCode));
        }
    }

    private function attemptCopy(
        Task $group,
        Instance $instance,
        Instance $source,
        bool $visitable,
        string $name,
        string $defaultBranch,
        string $expectedHead,
    ): Instance {
        try {
            $copied = $this->copies->copyOntoFetchedTip(
                $source,
                $instance,
                $name,
                $defaultBranch,
                $expectedHead,
                'instance.path_taken',
            );

            if (
                ! in_array($copied->mode, [InstanceCopyMode::Reflink, InstanceCopyMode::Full], true)
                || preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $copied->head) !== 1
            ) {
                throw new ResourceOperationException(
                    'instance.copy_failed',
                    'The copy returned invalid evidence.',
                    409,
                );
            }

            $this->isolation->execute($source, $instance);
            $resolved = $this->acceptCopiedCheckout($instance, $copied, $name);

            try {
                $this->copies->deleteMarker($resolved);
            } catch (Throwable) {
                // A leftover marker names this Instance and sits outside the checkout.
            }
        } catch (Throwable $exception) {
            $code = self::operationErrorCode($exception);

            if (! $this->discardOwnedCopy($instance)) {
                $this->recordWorkspace($group, InstanceCreation::Copy, null, self::copyFailedReason($code));

                if ($exception instanceof ResourceOperationException || $exception instanceof RuntimeConvergenceException) {
                    throw $exception;
                }

                throw new ResourceOperationException('instance.copy_failed', 'The copy failed.', 409);
            }

            throw new TaskWorkspaceCopyFallback($code);
        }

        $this->recordWorkspace($group, InstanceCreation::Copy, $resolved->copy_mode, null);

        return $this->activateDevelopment($resolved, $visitable);
    }

    private function fallBackOnReservedRow(Task $group, Instance $instance, bool $visitable, string $reason): Instance
    {
        DB::transaction(function () use ($instance): void {
            InstanceEnvironmentValue::query()->where('instance_id', $instance->id)->delete();
            DatabaseConnectionTarget::query()->where('instance_id', $instance->id)->delete();
            $instance->update([
                'creation' => InstanceCreation::Repository,
                'source_instance_id' => null,
                'copy_mode' => null,
                'status' => InstanceState::Reserved,
                'branch' => null,
                'starting_commit' => null,
            ]);
        });
        $resolved = $this->finishFresh($instance->refresh(), $visitable);
        $this->recordWorkspace($group, InstanceCreation::Repository, null, $reason);

        return $resolved;
    }

    /**
     * A copied row past reserved whose checkout cannot be inspected has lost its tree.
     * Rebuilding it avoids inspecting that path on every later claim.
     */
    private function copiedCheckoutNeedsRebuild(Instance $instance): bool
    {
        $instance->refresh();

        return $instance->creation === InstanceCreation::Copy
            && in_array($instance->status, [InstanceState::CheckoutPrepared, InstanceState::SourceResolved], true);
    }

    private function rebuildMissingCopiedCheckout(
        Task $group,
        Instance $instance,
        bool $visitable,
        ResourceOperationException|RuntimeConvergenceException $exception,
    ): Instance {
        if (! $this->discardOwnedCopy($instance)) {
            // The tree is still present, so this was not a missing checkout. Leave recorded copy evidence alone.
            throw $exception;
        }

        return $this->fallBackOnReservedRow(
            $group,
            $instance,
            $visitable,
            self::copyFailedReason($exception->errorCode),
        );
    }

    private static function operationErrorCode(Throwable $exception): string
    {
        if ($exception instanceof ResourceOperationException || $exception instanceof RuntimeConvergenceException) {
            return $exception->errorCode;
        }

        return 'instance.copy_failed';
    }

    private function eligibleSource(Task $group, Node $node): ?Instance
    {
        $source = Instance::query()
            ->where('project_id', $group->project_id)
            ->where('node_id', $node->id)
            ->where('name', 'default')
            ->first();

        if (! $source instanceof Instance || ! $this->sourceIsEligible($source)) {
            return null;
        }

        return $source->loadMissing(['project', 'node.roles']);
    }

    private function sourceIsEligible(Instance $source): bool
    {
        return $source->status === InstanceState::Active
            && $source->source_layout === InstanceSourceLayout::Checkout->value
            && $source->placedOnAppDev()
            && is_string($source->branch)
            && GitBranchName::isValid($source->branch);
    }

    private function reserveWorkspace(
        Task $group,
        Node $node,
        StoragePath $checkout,
        string $name,
        bool $visitable,
        ?Instance $source,
    ): Instance {
        return Instance::query()->create([
            'project_id' => $group->project_id,
            'node_id' => $node->id,
            'name' => $name,
            'source_layout' => InstanceSourceLayout::Checkout,
            'checkout_path' => $checkout->value,
            'root' => $visitable ? $group->project->root : null,
            'branch_override' => $name,
            'creation' => $source instanceof Instance ? InstanceCreation::Copy : InstanceCreation::Repository,
            'source_instance_id' => $source?->id,
            'task_workspace_routed' => $visitable,
            'status' => InstanceState::Reserved,
        ]);
    }

    private function acceptCopiedCheckout(Instance $instance, DevelopmentInstanceCopyResult $copied, string $branch): Instance
    {
        $this->transition($instance, InstanceState::Reserved, [
            'branch' => $branch,
            'starting_commit' => $copied->head,
            'copy_mode' => $copied->mode,
            'status' => InstanceState::CheckoutPrepared,
        ]);
        $instance->refresh()->loadMissing(['project', 'node']);
        $this->source->inspectPrepared($instance);
        $this->assertStoredResolution($instance, $this->source->inspectResolved($instance));
        $this->transition($instance, InstanceState::CheckoutPrepared, [
            'status' => InstanceState::SourceResolved,
        ]);
        $instance = $instance->refresh();
        $this->source->inspectPrepared($instance);
        $this->assertStoredResolution($instance, $this->source->inspectResolved($instance));

        return $instance->refresh();
    }

    private function discardOwnedCopy(Instance $instance): bool
    {
        try {
            $this->copies->discardPartial($instance);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function deleteWorkspaceRow(Instance $instance): void
    {
        DB::transaction(static function () use ($instance): void {
            InstanceEnvironmentValue::query()->where('instance_id', $instance->id)->delete();
            DatabaseConnectionTarget::query()->where('instance_id', $instance->id)->delete();
            Instance::query()->whereKey($instance->id)->delete();
        });
    }

    /**
     * A crash after the checkout exists and before the task stores its workspace fields still has
     * the evidence on the Instance. Fill the task from that row and do not copy again.
     */
    private function rememberWorkspace(Task $group, Instance $instance): void
    {
        if ($group->fresh()?->workspace_creation !== null) {
            return;
        }

        $this->recordWorkspace($group, $instance->creation, $instance->copy_mode, null);
    }

    private function recordWorkspace(Task $group, string $creation, ?string $mode, ?string $reason): void
    {
        Task::topLevel()->whereKey($group->id)->update([
            'workspace_creation' => $creation,
            'workspace_copy_mode' => $mode,
            'workspace_fallback_reason' => $reason,
        ]);
    }

    /**
     * An existing workspace keeps the mode recorded at creation. The Project setting
     * applies only when no workspace exists. A row with no record is routed only when
     * it already chose a Route, matching the one-time migration.
     */
    private function routingForClaim(?Instance $workspace, bool $selected): bool
    {
        if (! $workspace instanceof Instance) {
            return $selected;
        }

        if (is_bool($workspace->task_workspace_routed)) {
            return $workspace->task_workspace_routed;
        }

        return $workspace->status === InstanceState::Active
            || (is_string($workspace->root) && $workspace->root !== '')
            || $workspace->routes()->exists();
    }

    /**
     * A resumed workspace keeps the mode stored when it was created.
     * A row that has no record adopts the mode this claim already resolved.
     */
    private function recordedRouting(Instance $instance, bool $selected): bool
    {
        if (is_bool($instance->task_workspace_routed)) {
            return $instance->task_workspace_routed;
        }

        $instance->update(['task_workspace_routed' => $selected]);

        return $selected;
    }

    private function prepareSource(Instance $instance): Instance
    {
        while (true) {
            $instance->refresh()->loadMissing(['project', 'node']);

            if ($instance->status === InstanceState::Reserved) {
                $this->source->prepare($instance, false);
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

            $this->source->inspectPrepared($instance);
            $this->assertStoredResolution($instance, $this->source->inspectResolved($instance));

            return $instance->refresh();
        }
    }

    /** @param array<string, mixed> $attributes */
    private function transition(Instance $instance, InstanceState $from, array $attributes): void
    {
        DB::transaction(function () use ($instance, $from, $attributes): void {
            $locked = Instance::query()->lockForUpdate()->findOrFail($instance->id);

            if ($locked->status !== $from) {
                throw new ResourceOperationException(
                    'instance.lifecycle_conflict',
                    'Instance lifecycle evidence changed.',
                    409,
                );
            }

            $locked->update($attributes);
        });
    }

    private function assertResolution(Instance $instance, DevelopmentSourceResolution $resolution): void
    {
        if (
            $resolution->branch !== $this->expectedBranch($instance)
            || preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $resolution->startingCommit) !== 1
        ) {
            throw new ResourceOperationException(
                'instance.source_identity_invalid',
                'Resolved source identity is invalid.',
                409,
            );
        }
    }

    private function assertStoredResolution(
        Instance $instance,
        DevelopmentSourceResolution $resolution,
    ): void {
        if (
            $instance->branch !== $this->expectedBranch($instance)
            || ! is_string($instance->starting_commit)
            || preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $instance->starting_commit) !== 1
        ) {
            throw new ResourceOperationException(
                'instance.source_identity_changed',
                'Instance source identity changed.',
                409,
            );
        }

        $this->assertResolution($instance, $resolution);

        if (
            $instance->branch !== $resolution->branch
            || $instance->starting_commit !== $resolution->startingCommit
        ) {
            throw new ResourceOperationException(
                'instance.source_identity_changed',
                'Instance source identity changed.',
                409,
            );
        }
    }

    private function expectedBranch(Instance $instance): string
    {
        if (is_string($instance->branch_override) && $instance->branch_override !== '') {
            return $instance->branch_override;
        }

        return $instance->name;
    }

    private function hasSourceDefaults(Project $project, bool $visitable): bool
    {
        if (! is_string($project->default_branch) || ! GitBranchName::isValid($project->default_branch)) {
            return false;
        }

        if (! GitRepositoryOrigin::isValid($project->repository_url)) {
            return false;
        }

        if (! $visitable) {
            return true;
        }

        return is_string($project->root) && ProjectRoot::isValid($project->root, $project->type);
    }

    /**
     * A workspace that an interrupted claim created but never attached keeps its Node, so a later claim resumes it
     * there instead of creating a second one.
     */
    private function existingWorkspace(Task $group): ?Instance
    {
        $name = TaskWorkspaceName::for($group);

        return Instance::query()
            ->where('project_id', $group->project_id)
            ->where('name', $name)
            ->where('branch_override', $name)
            ->first();
    }

    private function existingWorkspaceNodeId(?Instance $workspace): ?int
    {
        $nodeId = $workspace?->node_id;

        return is_numeric($nodeId) ? (int) $nodeId : null;
    }

    /**
     * @param  list<string>  $drivers  Every driver the group uses must allow the Node.
     * @param  int|null  $pinnedNodeId  The Node of the group's existing workspace. Only that Node can then fit.
     */
    private function selectNode(Project $project, array $drivers, ?int $pinnedNodeId = null): ?Node
    {
        $nodes = Node::query()
            ->where('status', LifecycleStatus::Active)
            ->where('platform', 'linux')
            ->whereHas(
                'roles',
                static fn ($query) => $query
                    ->where('role', RoleName::AppDev)
                    ->where('status', LifecycleStatus::Active),
            )
            ->whereDoesntHave(
                'projectNodeExclusions',
                static fn ($query) => $query->where('project_id', $project->id),
            )
            ->orderBy('id')
            ->get();

        $fitting = $nodes
            ->filter(static fn (Node $node): bool => $pinnedNodeId === null || $node->id === $pinnedNodeId)
            ->filter(fn (Node $node): bool => array_all($drivers, fn (string $driver): bool => $this->drivers->get($driver)->allows($node)));

        $selected = $fitting
            ->filter(fn (Node $node): bool => $this->hasCapacity($node))
            ->sortBy(fn (Node $node): array => [$this->ceilings->activeForNode($node->id), $node->id])
            ->first();

        if ($selected instanceof Node) {
            return $selected;
        }

        if ($fitting->isNotEmpty()) {
            throw new TaskCapacityException(fleetFull: ! $this->anyAppDevNodeHasCapacity());
        }

        return null;
    }

    private function hasCapacity(Node $node): bool
    {
        return $this->ceilings->activeForNode($node->id) < TaskCeilings::PerNode;
    }

    private function anyAppDevNodeHasCapacity(): bool
    {
        return Node::query()
            ->where('status', LifecycleStatus::Active)
            ->where('platform', 'linux')
            ->whereHas(
                'roles',
                static fn ($query) => $query
                    ->where('role', RoleName::AppDev)
                    ->where('status', LifecycleStatus::Active),
            )
            ->get()
            ->contains(fn (Node $node): bool => $this->hasCapacity($node));
    }
}

final class TaskWorkspaceCopyFallback extends RuntimeException
{
    public function __construct(public string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
