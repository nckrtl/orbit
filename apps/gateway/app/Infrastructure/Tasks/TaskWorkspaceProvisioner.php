<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\DevelopmentInstanceProvisioner;
use App\Domain\Instances\DevelopmentInstanceSourceLifecycle;
use App\Domain\Instances\DevelopmentSourceResolution;
use App\Domain\Instances\InstanceDestinationGuard;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\ManagedCheckoutOverlap;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
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
use App\Domain\Tasks\TaskWorkspaceTopology;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class TaskWorkspaceProvisioner implements InstanceProvisioning
{
    public function __construct(
        private ManagedUserAccountResolver $accounts,
        private StorageRootResolver $storageRoots,
        private NodeSettingsNormalizer $nodeSettings,
        private ManagedCheckoutOverlap $checkoutOverlap,
        private InstanceDestinationGuard $destinationGuard,
        private AppDevSourceOperationLock $sourceLock,
        private DevelopmentInstanceSourceLifecycle $source,
        private DevelopmentInstanceProvisioner $development,
        private TaskConcurrencyGuard $ceilings,
        private AgentDriverRegistry $drivers,
        private ?TaskWorkspaceTopology $topology = null,
    ) {}

    public function provision(InstanceProvisionIntent $intent): ?Instance
    {
        $workspace = $this->provisionWorkspace($intent);

        if ($workspace instanceof Instance) {
            $this->acquireTopology($workspace, $intent->group);
        }

        return $workspace;
    }

    /**
     * Agents use the group's topology but cannot acquire one, because that changes host firewall rules. Acquiring is
     * best effort: most groups never need a topology, so a failure is logged and does not stop the group.
     */
    private function acquireTopology(Instance $workspace, Task $group): void
    {
        try {
            ($this->topology ?? app(TaskWorkspaceTopology::class))->acquire($workspace, $group->id);
        } catch (Throwable $exception) {
            Log::warning('The task topology could not be acquired; the group continues without it.', [
                'task_group_id' => $group->id,
                'instance_id' => $workspace->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function provisionWorkspace(InstanceProvisionIntent $intent): ?Instance
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

            $instance = $existing;
            $visitable = $this->recordedRouting($instance, $visitable);
        } else {
            $account = $this->accounts->resolve($node);
            $roots = $this->storageRoots->resolveApps(
                $this->nodeSettings->fromStored($node->settings),
                $account,
            );
            $checkout = $roots->append($group->project->slug, $name);
            $this->checkoutOverlap->assertAvailable($node->id, $checkout, 'instance.path_taken');
            $this->destinationGuard->assertUnoccupied($node, $checkout);

            $instance = Instance::query()->create([
                'project_id' => $group->project_id,
                'node_id' => $node->id,
                'name' => $name,
                'source_layout' => InstanceSourceLayout::Checkout,
                'checkout_path' => $checkout->value,
                'root' => $visitable ? $group->project->root : null,
                'branch_override' => $name,
                'task_workspace_routed' => $visitable,
                'status' => InstanceState::Reserved,
            ]);
        }

        return $this->sourceLock->synchronized(
            $instance->node_id,
            function () use ($instance, $visitable): Instance {
                $resolved = $this->prepareSource($instance);
                $this->source->inspectPrepared($resolved);

                if (! $visitable) {
                    return $resolved;
                }

                $this->development->reserve($resolved, null);

                return $this->development->complete($resolved, null);
            },
        );
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
