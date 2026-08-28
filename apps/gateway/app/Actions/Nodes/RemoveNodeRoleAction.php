<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Firewall\FirewallOperationException;
use App\Domain\Nodes\NodeRoleDependencyInspector;
use App\Domain\Nodes\NodeRoleDependencySet;
use App\Domain\Nodes\NodeRoleDependentCleaner;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\NodeRoleToolIntentGuard;
use App\Domain\Nodes\NodeRoleValidationException;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\RoleRegistry;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerScopeLock;
use App\Domain\Tools\ToolManagerScopeLockException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Process;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * @mago-expect lint:cyclomatic-complexity Removal keeps its ordered recovery and error mapping in one action boundary.
 * @mago-expect lint:too-many-methods The action keeps removal state transitions and recovery in one transaction boundary.
 */
final readonly class RemoveNodeRoleAction
{
    /** @mago-expect lint:excessive-parameter-list The action requires each narrow lifecycle collaborator explicitly. */
    public function __construct(
        private NodeRoleDependencyInspector $inspector,
        private NodeRoleDependentCleaner $cleaner,
        private RoleBaselineConverger $baselines,
        private RoleRegistry $registry,
        private NodeRoleToolIntentGuard $toolIntentGuard,
        private ToolManagerScopeLock $managerScope,
    ) {}

    /**
     * @mago-expect lint:no-boolean-flag-parameter The public removal contract carries explicit consent and purge choices.
     */
    public function execute(
        Node $node,
        RoleName $role,
        bool $force = false,
        bool $purgeData = false,
    ): NodeRoleDependencySet {
        $this->guardPolicy($node, $role);
        $this->toolIntentGuard->assertRemovalSafe($node, $role);
        $preview = $this->withRetirementPreview(
            $this->inspector->inspect($node, $role),
            $node,
            $role,
        );

        if (! $force) {
            throw new NodeRoleValidationException(
                message: 'Use --force to remove this node role.',
                details: [
                    'field' => 'force',
                    'reason' => 'destructive_consent_required',
                    'role' => $role->value,
                    'dependents' => $preview->summaries,
                ],
            );
        }

        if ($this->isAppRole($role)) {
            return $this->removeAppRole($node, $role, $purgeData);
        }

        return $this->removeClaimedRole($node, $role, $purgeData);
    }

    private function removeAppRole(Node $node, RoleName $role, bool $purgeData): NodeRoleDependencySet
    {
        try {
            return $this->managerScope->run(
                $node->id,
                ToolManagerName::Vp,
                fn (): NodeRoleDependencySet => $this->managerScope->run(
                    $node->id,
                    ToolManagerName::Composer,
                    fn (): NodeRoleDependencySet => $this->removeClaimedRole($node, $role, $purgeData),
                ),
            );
        } catch (ToolManagerScopeLockException $exception) {
            throw new NodeRoleOperationException(
                step: 'tool-manager-lock',
                errorCode: 'node_role.remove_failed',
                underlyingErrorCode: 'node_role.tool_manager_locked',
                message: "Tool manager state is busy on node [{$node->name}].",
                previous: $exception,
            );
        }
    }

    private function removeClaimedRole(Node $node, RoleName $role, bool $purgeData): NodeRoleDependencySet
    {
        [$assignment, $dependencies] = $this->claim($node, $role);

        try {
            $this->cleaner->clean($dependencies);
        } catch (Throwable $exception) {
            $this->failRemoval($assignment, $this->cleanupFailure($exception));
        }

        try {
            $this->baselines->remove($node, $assignment, $purgeData);
        } catch (Throwable $exception) {
            $this->failRemoval($assignment, $this->baselineFailure($node, $role, $exception));
        }

        try {
            $this->finalize($node, $role, $assignment, $dependencies);
        } catch (Throwable $exception) {
            $failure = $exception instanceof NodeRoleOperationException
                ? $exception
                : new NodeRoleOperationException(
                    step: 'dependency-race',
                    errorCode: 'node_role.remove_failed',
                    underlyingErrorCode: 'node_role.dependencies_changed',
                    message: "Role [{$role->value}] dependencies changed during removal from node [{$node->name}].",
                    previous: $exception,
                );
            $this->failRemoval($assignment, $failure);
        }

        return $dependencies;
    }

    private function isAppRole(RoleName $role): bool
    {
        return $role === RoleName::AppDev || $role === RoleName::AppProd;
    }

    /** @return array{NodeRole, NodeRoleDependencySet} */
    private function claim(Node $node, RoleName $role): array
    {
        /**
         * @var array{NodeRole, NodeRoleDependencySet} $claim
         * @mago-expect lint:inline-variable-return The annotation narrows Laravel's transaction result.
         */
        $claim = DB::transaction(function () use ($node, $role): array {
            $assignment = NodeRole::query()
                ->where('node_id', $node->id)
                ->where('role', $role)
                ->lockForUpdate()
                ->firstOrFail();
            $this->guardPolicy($node->refresh(), $role);
            $this->toolIntentGuard->assertRemovalSafe($node, $role);

            if (! $this->canClaim($assignment)) {
                throw new NodeRoleValidationException(
                    "Role [{$role->value}] cannot be removed from status [{$assignment->status->value}].",
                );
            }

            $dependencies = $this->withRetirementPreview(
                $this->inspector->inspect($node, $role),
                $node,
                $role,
            );
            $assignment->update([
                'status' => LifecycleStatus::Removing,
                'failed_step' => null,
                'error_code' => null,
            ]);
            Process::query()
                ->whereIn('id', $dependencies->processIds)
                ->update([
                    'status' => LifecycleStatus::Removing,
                    'failed_step' => null,
                    'error_code' => null,
                ]);
            Workspace::query()
                ->whereIn('id', $dependencies->workspaceIds)
                ->update([
                    'status' => LifecycleStatus::Removing,
                    'failed_step' => null,
                    'error_code' => null,
                ]);
            Instance::query()
                ->whereIn('id', $dependencies->instanceIds)
                ->update([
                    'status' => LifecycleStatus::Removing,
                    'failed_step' => null,
                    'error_code' => null,
                ]);

            return [$assignment->refresh(), $dependencies];
        });

        return $claim;
    }

    private function finalize(
        Node $node,
        RoleName $role,
        NodeRole $assignment,
        NodeRoleDependencySet $captured,
    ): void {
        DB::transaction(function () use ($node, $role, $assignment, $captured): void {
            $current = $this->inspector->inspect($node, $role);

            if (! $this->sameDependencies($captured, $current)) {
                throw new NodeRoleOperationException(
                    step: 'dependency-race',
                    errorCode: 'node_role.remove_failed',
                    underlyingErrorCode: 'node_role.dependencies_changed',
                    message: "Role [{$role->value}] dependencies changed during removal from node [{$node->name}].",
                );
            }

            Process::query()->whereIn('id', $captured->processIds)->delete();
            Workspace::query()->whereIn('id', $captured->workspaceIds)->delete();
            Instance::query()->whereIn('id', $captured->instanceIds)->delete();
            $assignment->delete();
            $this->toolIntentGuard->assertRemovalSafe($node, $role);
            $this->toolIntentGuard->retireUnsupportedManagers($node);
        });
    }

    private function guardPolicy(Node $node, RoleName $role): void
    {
        if (! $node->exists || $node->status !== LifecycleStatus::Active) {
            throw new NodeRoleValidationException('Roles can be changed only on an active node.');
        }

        if (! $this->registry->definition($role)->mutable) {
            throw new NodeRoleValidationException("Role [{$role->value}] is protected from removal.");
        }

        if (! NodeRole::query()->where('node_id', $node->id)->where('role', $role)->exists()) {
            throw new NodeRoleValidationException("Role [{$role->value}] is not assigned to node [{$node->name}].");
        }
    }

    private function canClaim(NodeRole $assignment): bool
    {
        return (
            $assignment->status === LifecycleStatus::Active
            || $assignment->status === LifecycleStatus::Failed
            && is_string($assignment->failed_step)
            && str_starts_with($assignment->failed_step, 'remove:')
        );
    }

    private function sameDependencies(NodeRoleDependencySet $captured, NodeRoleDependencySet $current): bool
    {
        return (
            $captured->instanceIds === $current->instanceIds
            && $captured->workspaceIds === $current->workspaceIds
            && $captured->processIds === $current->processIds
        );
    }

    private function withRetirementPreview(
        NodeRoleDependencySet $dependencies,
        Node $node,
        RoleName $role,
    ): NodeRoleDependencySet {
        $retirementPreview = $this->toolIntentGuard->retirementPreview($node, $role);

        if ($retirementPreview === []) {
            return $dependencies;
        }

        $summaries = [
            ...$dependencies->summaries,
            ...$retirementPreview,
        ];
        sort($summaries, SORT_STRING);

        return new NodeRoleDependencySet(
            $dependencies->instanceIds,
            $dependencies->workspaceIds,
            $dependencies->processIds,
            $summaries,
        );
    }

    private function cleanupFailure(Throwable $exception): NodeRoleOperationException
    {
        if ($exception instanceof NodeRoleOperationException) {
            return $exception;
        }

        if ($exception instanceof ProcessOperationException || $exception instanceof RuntimeConvergenceException) {
            return new NodeRoleOperationException(
                step: $exception->step,
                errorCode: 'node_role.remove_failed',
                underlyingErrorCode: $exception->errorCode,
                message: $exception->getMessage(),
                result: $exception->result,
                previous: $exception,
            );
        }

        return new NodeRoleOperationException(
            step: 'dependents',
            errorCode: 'node_role.remove_failed',
            underlyingErrorCode: 'node_role.cleanup_unknown',
            message: 'Node role dependent cleanup failed.',
            previous: $exception,
        );
    }

    private function baselineFailure(Node $node, RoleName $role, Throwable $exception): NodeRoleOperationException
    {
        if ($exception instanceof NodeRoleOperationException) {
            return $exception;
        }

        if ($exception instanceof FirewallOperationException || $exception instanceof RuntimeConvergenceException) {
            return new NodeRoleOperationException(
                step: $exception->step,
                errorCode: 'node_role.remove_failed',
                underlyingErrorCode: $exception->errorCode,
                message: $exception->getMessage(),
                result: $exception->result,
                previous: $exception,
            );
        }

        return new NodeRoleOperationException(
            step: 'baseline',
            errorCode: 'node_role.remove_failed',
            underlyingErrorCode: 'node_role.remove_unknown',
            message: "Role [{$role->value}] baseline removal failed on node [{$node->name}].",
            previous: $exception,
        );
    }

    private function failRemoval(NodeRole $assignment, NodeRoleOperationException $exception): never
    {
        DB::transaction(static fn () => NodeRole::query()
            ->whereKey($assignment->id)
            ->update([
                'status' => LifecycleStatus::Failed,
                'failed_step' => "remove:{$exception->step}",
                'error_code' => $exception->underlyingErrorCode,
            ]));

        throw new NodeRoleOperationException(
            step: "remove:{$exception->step}",
            errorCode: $exception->errorCode,
            underlyingErrorCode: $exception->underlyingErrorCode,
            message: $exception->getMessage(),
            result: $exception->result,
            previous: $exception,
        );
    }
}
