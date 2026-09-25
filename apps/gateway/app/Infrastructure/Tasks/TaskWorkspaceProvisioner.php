<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\AppInstanceDestinationGuard;
use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\DevelopmentAppInstanceProvisioner;
use App\Domain\AppInstances\DevelopmentAppInstanceSourceLifecycle;
use App\Domain\AppInstances\DevelopmentSourceResolution;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeAccessAuthorizer;
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
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\TaskGroup;
use Illuminate\Support\Facades\DB;

final readonly class TaskWorkspaceProvisioner implements InstanceProvisioning
{
    public function __construct(
        private ManagedUserAccountResolver $accounts,
        private StorageRootResolver $storageRoots,
        private NodeSettingsNormalizer $nodeSettings,
        private ManagedCheckoutOverlap $checkoutOverlap,
        private AppInstanceDestinationGuard $destinationGuard,
        private AppDevSourceOperationLock $sourceLock,
        private DevelopmentAppInstanceSourceLifecycle $source,
        private DevelopmentAppInstanceProvisioner $development,
        private TaskConcurrencyGuard $ceilings,
        private AgentDriverRegistry $drivers,
        private NodeAccessAuthorizer $access,
    ) {}

    public function provision(InstanceProvisionIntent $intent): ?AppInstance
    {
        $group = $intent->group->loadMissing(['app', 'taskable']);
        $existing = $group->taskable;

        if ($existing instanceof AppInstance) {
            return $existing;
        }

        if (! $this->hasSourceDefaults($group->app, $intent->visitable)) {
            return null;
        }

        $node = $this->selectNode($group->app, [$intent->group->implementer_agent_driver, $intent->group->reviewer_agent_driver], $intent->selfAccess, $this->existingWorkspaceNodeId($group));

        if (! $node instanceof Node) {
            return null;
        }

        try {
            return $this->createWorkspace($group, $node, $intent->visitable);
        } catch (ResourceOperationException|RuntimeConvergenceException) {
            return null;
        }
    }

    private function createWorkspace(TaskGroup $group, Node $node, bool $visitable): AppInstance
    {
        $name = TaskWorkspaceName::for($group);
        $existing = AppInstance::query()
            ->where('app_id', $group->app_id)
            ->where('name', $name)
            ->first();

        if ($existing instanceof AppInstance) {
            if ($existing->node_id !== $node->id) {
                throw new ResourceOperationException(
                    'instance.placement_conflict',
                    'AppInstance placement is immutable.',
                    409,
                );
            }

            $appInstance = $existing;
        } else {
            $account = $this->accounts->resolve($node);
            $roots = $this->storageRoots->resolveApps(
                $this->nodeSettings->fromStored($node->settings),
                $account,
            );
            $checkout = $roots->instance->append($group->app->slug, $name);
            $this->checkoutOverlap->assertAvailable($node->id, $checkout, 'instance.path_taken');
            $this->destinationGuard->assertUnoccupied($node, $checkout);

            $appInstance = AppInstance::query()->create([
                'app_id' => $group->app_id,
                'node_id' => $node->id,
                'name' => $name,
                'source_layout' => AppInstanceSourceLayout::Checkout,
                'checkout_path' => $checkout->value,
                'root' => $visitable ? $group->app->root : null,
                'branch_override' => $name,
                'status' => AppInstanceState::Reserved,
            ]);
        }

        return $this->sourceLock->synchronized(
            $appInstance->node_id,
            function () use ($appInstance, $visitable): AppInstance {
                $resolved = $this->prepareSource($appInstance);

                if (! $visitable) {
                    return $resolved;
                }

                $this->development->reserve($resolved, null);

                return $this->development->complete($resolved, null);
            },
        );
    }

    private function prepareSource(AppInstance $appInstance): AppInstance
    {
        while (true) {
            $appInstance->refresh()->loadMissing(['app', 'node']);

            if ($appInstance->status === AppInstanceState::Reserved) {
                $this->source->prepare($appInstance, false);
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

            $this->source->inspectPrepared($appInstance);
            $this->assertStoredResolution($appInstance, $this->source->inspectResolved($appInstance));

            return $appInstance->refresh();
        }
    }

    /** @param array<string, mixed> $attributes */
    private function transition(AppInstance $appInstance, AppInstanceState $from, array $attributes): void
    {
        DB::transaction(function () use ($appInstance, $from, $attributes): void {
            $locked = AppInstance::query()->lockForUpdate()->findOrFail($appInstance->id);

            if ($locked->status !== $from) {
                throw new ResourceOperationException(
                    'instance.lifecycle_conflict',
                    'AppInstance lifecycle evidence changed.',
                    409,
                );
            }

            $locked->update($attributes);
        });
    }

    private function assertResolution(AppInstance $appInstance, DevelopmentSourceResolution $resolution): void
    {
        if (
            $resolution->branch !== $this->expectedBranch($appInstance)
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
        AppInstance $appInstance,
        DevelopmentSourceResolution $resolution,
    ): void {
        if (
            $appInstance->branch !== $this->expectedBranch($appInstance)
            || ! is_string($appInstance->starting_commit)
            || preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $appInstance->starting_commit) !== 1
        ) {
            throw new ResourceOperationException(
                'instance.source_identity_changed',
                'AppInstance source identity changed.',
                409,
            );
        }

        $this->assertResolution($appInstance, $resolution);

        if (
            $appInstance->branch !== $resolution->branch
            || $appInstance->starting_commit !== $resolution->startingCommit
        ) {
            throw new ResourceOperationException(
                'instance.source_identity_changed',
                'AppInstance source identity changed.',
                409,
            );
        }
    }

    private function expectedBranch(AppInstance $appInstance): string
    {
        if (is_string($appInstance->branch_override) && $appInstance->branch_override !== '') {
            return $appInstance->branch_override;
        }

        return $appInstance->name;
    }

    private function hasSourceDefaults(OrbitApp $app, bool $visitable): bool
    {
        if (! is_string($app->default_branch) || ! GitBranchName::isValid($app->default_branch)) {
            return false;
        }

        if (! GitRepositoryOrigin::isValid($app->repository_url)) {
            return false;
        }

        if (! $visitable) {
            return true;
        }

        return is_string($app->root) && ProjectRoot::isValid($app->root, $app->type);
    }

    /**
     * A workspace that an interrupted claim created but never attached keeps its Node, so a later claim resumes it
     * there instead of creating a second one.
     */
    private function existingWorkspaceNodeId(TaskGroup $group): ?int
    {
        $nodeId = AppInstance::query()
            ->where('app_id', $group->app_id)
            ->where('name', TaskWorkspaceName::for($group))
            ->value('node_id');

        return is_numeric($nodeId) ? (int) $nodeId : null;
    }

    /**
     * @param  list<string>  $drivers  Every driver the group uses must allow the Node.
     * @param  int|null  $pinnedNodeId  The Node of the group's existing workspace. Only that Node can then fit.
     */
    private function selectNode(OrbitApp $app, array $drivers, bool $selfAccess, ?int $pinnedNodeId = null): ?Node
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
                static fn ($query) => $query->where('app_id', $app->id),
            )
            ->orderBy('id')
            ->get();

        $fitting = $nodes
            ->filter(static fn (Node $node): bool => $pinnedNodeId === null || $node->id === $pinnedNodeId)
            ->filter(fn (Node $node): bool => array_all($drivers, fn (string $driver): bool => $this->drivers->get($driver)->allows($node)))
            ->filter(fn (Node $node): bool => ! $selfAccess || $this->access->allows($node, $node));

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
