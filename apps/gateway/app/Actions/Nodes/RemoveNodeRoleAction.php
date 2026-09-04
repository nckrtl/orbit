<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Firewall\FirewallOperationException;
use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Nodes\NodeReachabilityProbe;
use App\Domain\Nodes\NodeRoleDependencyInspector;
use App\Domain\Nodes\NodeRoleDependencySet;
use App\Domain\Nodes\NodeRoleDependentCleaner;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\NodeRoleRemovalOutcome;
use App\Domain\Nodes\NodeRoleToolIntentGuard;
use App\Domain\Nodes\NodeRoleValidationException;
use App\Domain\Nodes\NodeSideResidue;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\RoleRegistry;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Routes\RouteRemovalGuard;
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
        private NodeReachabilityProbe $reachability,
        private NodeSideResidue $residue,
        private ?RouteRemovalGuard $routes = null,
    ) {}

    /**
     * @mago-expect lint:no-boolean-flag-parameter The public removal contract carries explicit consent and purge choices.
     */
    public function execute(
        Node $node,
        RoleName $role,
        bool $force = false,
        bool $purgeData = false,
        bool $offline = false,
    ): NodeRoleRemovalOutcome {
        $this->routeGuard()->assertRoleRemovable($node, $role);

        if ($role === RoleName::Ingress) {
            return $this->removeIngress($node, $force);
        }

        if ($role === RoleName::AppDev && $node->appInstances()->exists()) {
            throw new NodeRoleValidationException(
                message: "Role [{$role->value}] cannot be removed while node [{$node->name}] owns AppInstances.",
                details: [
                    'reason' => 'app_instances_attached',
                    'role' => $role->value,
                ],
            );
        }

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

        // `--offline` states a belief about the node, not a licence to ignore
        // failures. Orbit checks the belief first, and a node that answers
        // takes the ordinary fail-closed path whether or not the flag was set.
        $degradation = $offline ? $this->reachability->degradation($node) : null;

        if ($this->isAppRole($role)) {
            return $this->removeAppRole($node, $role, $purgeData, $degradation);
        }

        return $this->removeClaimedRole($node, $role, $purgeData, $degradation);
    }

    private function removeIngress(Node $node, bool $force): NodeRoleRemovalOutcome
    {
        $this->guardMutableNode($node, RoleName::Ingress);
        $assignment = NodeRole::query()
            ->where('node_id', $node->id)
            ->where('role', RoleName::Ingress)
            ->first();

        if (! $force) {
            throw new NodeRoleValidationException(
                message: 'Use --force to remove this node role.',
                details: [
                    'field' => 'force',
                    'reason' => 'destructive_consent_required',
                    'role' => RoleName::Ingress->value,
                    'dependents' => [],
                ],
            );
        }

        if ($assignment instanceof NodeRole) {
            DB::transaction(static function () use ($assignment): void {
                NodeRole::query()->whereKey($assignment->id)->lockForUpdate()->sole()->delete();
            });
        }

        return new NodeRoleRemovalOutcome(new NodeRoleDependencySet([], [], [], []));
    }

    private function removeAppRole(
        Node $node,
        RoleName $role,
        bool $purgeData,
        ?ExporterDegradationReason $degradation,
    ): NodeRoleRemovalOutcome {
        try {
            return $this->managerScope->run(
                $node->id,
                ToolManagerName::Vp,
                fn (): NodeRoleRemovalOutcome => $this->managerScope->run(
                    $node->id,
                    ToolManagerName::Composer,
                    fn (): NodeRoleRemovalOutcome => $this->removeClaimedRole(
                        $node,
                        $role,
                        $purgeData,
                        $degradation,
                    ),
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

    private function removeClaimedRole(
        Node $node,
        RoleName $role,
        bool $purgeData,
        ?ExporterDegradationReason $degradation,
    ): NodeRoleRemovalOutcome {
        [$assignment, $dependencies] = $this->claim($node, $role);

        if ($degradation instanceof ExporterDegradationReason) {
            $this->abandonNodeSide($node, $role, $assignment);
        } else {
            $this->tearDownNodeSide($node, $role, $assignment, $dependencies, $purgeData);
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

        return new NodeRoleRemovalOutcome(
            dependencies: $dependencies,
            degradation: $degradation,
            retained: $degradation instanceof ExporterDegradationReason
                ? $this->residue->describe([$role], nodeLeavesFleet: false)
                : [],
        );
    }

    /**
     * The ordinary path: every dependent and every baseline step is torn down
     * on the node, and any failure leaves the assignment in `Failed`.
     *
     * @mago-expect lint:excessive-parameter-list The teardown needs the claimed assignment and its captured dependents.
     */
    private function tearDownNodeSide(
        Node $node,
        RoleName $role,
        NodeRole $assignment,
        NodeRoleDependencySet $dependencies,
        bool $purgeData,
    ): void {
        try {
            $this->cleaner->clean($dependencies);
        } catch (Throwable $exception) {
            $this->failRemoval($assignment, $this->offlineHint($this->cleanupFailure($exception), $node));
        }

        try {
            $this->baselines->remove($node, $assignment, $purgeData);
        } catch (Throwable $exception) {
            $this->failRemoval(
                $assignment,
                $this->offlineHint($this->baselineFailure($node, $role, $exception), $node),
            );
        }
    }

    /**
     * The unreachable path: nothing is attempted on the node, so no failure is
     * swallowed. The Gateway-side projection is still converged, and still
     * fails closed when it cannot be.
     */
    private function abandonNodeSide(Node $node, RoleName $role, NodeRole $assignment): void
    {
        try {
            $this->baselines->removeUnreachable($node, $assignment);
        } catch (Throwable $exception) {
            $this->failRemoval($assignment, $this->baselineFailure($node, $role, $exception));
        }
    }

    /**
     * Names the flag on a failure the operator may have meant to force.
     *
     * The hint is safe to give unconditionally: `--offline` re-checks the node
     * and falls back to this same path when it answers.
     */
    private function offlineHint(NodeRoleOperationException $exception, Node $node): NodeRoleOperationException
    {
        return new NodeRoleOperationException(
            step: $exception->step,
            errorCode: $exception->errorCode,
            underlyingErrorCode: $exception->underlyingErrorCode,
            message: $exception->getMessage()." Retry with --offline if node [{$node->name}] is unreachable.",
            result: $exception->result,
            previous: $exception,
        );
    }

    private function isAppRole(RoleName $role): bool
    {
        return $role === RoleName::AppDev || $role === RoleName::AppProd;
    }

    private function routeGuard(): RouteRemovalGuard
    {
        return $this->routes ?? app(RouteRemovalGuard::class);
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
            $this->routeGuard()->assertRoleRemovable($node, $role);
            $this->guardPolicy($node->refresh(), $role);

            if ($role === RoleName::AppDev && $node->appInstances()->exists()) {
                throw new NodeRoleValidationException(
                    message: "Role [{$role->value}] cannot be removed while node [{$node->name}] owns AppInstances.",
                    details: [
                        'reason' => 'app_instances_attached',
                        'role' => $role->value,
                    ],
                );
            }

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
        $this->guardMutableNode($node, $role);

        if (! NodeRole::query()->where('node_id', $node->id)->where('role', $role)->exists()) {
            throw new NodeRoleValidationException("Role [{$role->value}] is not assigned to node [{$node->name}].");
        }
    }

    private function guardMutableNode(Node $node, RoleName $role): void
    {
        if (! $node->exists || $node->status !== LifecycleStatus::Active) {
            throw new NodeRoleValidationException('Roles can be changed only on an active node.');
        }

        if (! $this->registry->definition($role)->mutable) {
            throw new NodeRoleValidationException("Role [{$role->value}] is protected from removal.");
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
