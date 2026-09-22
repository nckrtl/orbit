<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Data\Nodes\NodeData;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Firewall\FirewallOperationException;
use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Nodes\NodeReachabilityProbe;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\NodeRoleRemovalOutcome;
use App\Domain\Nodes\NodeRoleValidationException;
use App\Domain\Nodes\NodeSideResidue;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\RoleRegistry;
use App\Domain\Routes\RouteRemovalGuard;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerScopeLock;
use App\Domain\Tools\ToolManagerScopeLockException;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class RemoveNodeRoleAction
{
    public function __construct(
        private RoleBaselineConverger $baselines,
        private RoleRegistry $registry,
        private ToolManagerScopeLock $managerScope,
        private NodeReachabilityProbe $reachability,
        private NodeSideResidue $residue,
        private NodeRoleFirewallManager $firewall,
        private ?RouteRemovalGuard $routes = null,
        private ?RecordEventBroadcaster $broadcaster = null,
    ) {}

    public function execute(
        Node $node,
        RoleName $role,
        bool $force = false,
        bool $purgeData = false,
        bool $offline = false,
    ): NodeRoleRemovalOutcome {
        $this->routeGuard()->assertRoleRemovable($node, $role);

        if ($role === RoleName::Ingress) {
            return $this->announceUpdated($node, $this->removeIngress($node, $force));
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

        if (! $force) {
            throw new NodeRoleValidationException(
                message: 'Use --force to remove this node role.',
                details: [
                    'field' => 'force',
                    'reason' => 'destructive_consent_required',
                    'role' => $role->value,
                    'dependents' => [],
                ],
            );
        }

        // `--offline` states a belief about the node, not a licence to ignore
        // failures. Orbit checks the belief first, and a node that answers
        // takes the ordinary fail-closed path whether or not the flag was set.
        $degradation = $offline ? $this->reachability->degradation($node) : null;

        if ($this->isAppRole($role)) {
            return $this->announceUpdated($node, $this->removeAppRole($node, $role, $purgeData, $degradation));
        }

        return $this->announceUpdated($node, $this->removeClaimedRole($node, $role, $purgeData, $degradation));
    }

    private function announceUpdated(Node $node, NodeRoleRemovalOutcome $outcome): NodeRoleRemovalOutcome
    {
        ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
            RecordEventType::NodeUpdated,
            $node->id,
            NodeData::fromModel($node->refresh())->toArray(),
        );

        return $outcome;
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

        return new NodeRoleRemovalOutcome;
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
        $assignment = $this->claim($node, $role);

        if ($degradation instanceof ExporterDegradationReason) {
            $this->abandonNodeSide($node, $role, $assignment);
        } else {
            $this->tearDownNodeSide($node, $role, $assignment, $purgeData);
        }

        try {
            $this->finalize($assignment);
        } catch (Throwable $exception) {
            $failure = $exception instanceof NodeRoleOperationException
                ? $exception
                : new NodeRoleOperationException(
                    step: 'finalize',
                    errorCode: 'node_role.remove_failed',
                    underlyingErrorCode: 'node_role.finalize_failed',
                    message: "Role [{$role->value}] removal could not be finalized on node [{$node->name}].",
                    previous: $exception,
                );
            $this->failRemoval($assignment, $failure);
        }

        return new NodeRoleRemovalOutcome(
            degradation: $degradation,
            retained: $degradation instanceof ExporterDegradationReason
                ? $this->residue->describe([$role], nodeLeavesFleet: false)
                : [],
        );
    }

    /**
     * The ordinary path: every baseline step is torn down
     * on the node, and any failure leaves the assignment in `Failed`.
     */
    private function tearDownNodeSide(
        Node $node,
        RoleName $role,
        NodeRole $assignment,
        bool $purgeData,
    ): void {
        try {
            $this->baselines->remove($node, $assignment, $purgeData);
        } catch (Throwable $exception) {
            $this->failRemoval(
                $assignment,
                $this->offlineHint($this->baselineFailure($node, $role, $exception), $node),
            );
        }

        if (! $this->isLastRole($node, $assignment)) {
            return;
        }

        try {
            $this->firewall->restorePublicSsh($node, $node->user);
        } catch (Throwable $exception) {
            $this->failRemoval(
                $assignment,
                $this->offlineHint($this->recoveryFailure($node, $role, $exception), $node),
            );
        }
    }

    /**
     * Whether this assignment is the node's only role row.
     *
     * Any other row counts, including a provisioning or failed one, so public
     * SSH stays closed while another role convergence can still be retried.
     * That matches the path the retarget selects from stored state.
     */
    private function isLastRole(Node $node, NodeRole $assignment): bool
    {
        return NodeRole::query()
            ->where('node_id', $node->id)
            ->whereKeyNot($assignment->id)
            ->doesntExist();
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

    private function claim(Node $node, RoleName $role): NodeRole
    {
        return DB::transaction(function () use ($node, $role): NodeRole {
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

            if (! $this->canClaim($assignment)) {
                throw new NodeRoleValidationException(
                    "Role [{$role->value}] cannot be removed from status [{$assignment->status->value}].",
                );
            }

            $assignment->update([
                'status' => LifecycleStatus::Removing,
                'failed_step' => null,
                'error_code' => null,
            ]);

            return $assignment->refresh();
        });
    }

    private function finalize(NodeRole $assignment): void
    {
        DB::transaction(static function () use ($assignment): void {
            NodeRole::query()->whereKey($assignment->id)->lockForUpdate()->sole()->delete();
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
        return
            $assignment->status === LifecycleStatus::Active
            || $assignment->status === LifecycleStatus::Failed
            && is_string($assignment->failed_step)
            && str_starts_with($assignment->failed_step, 'remove:');
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

    private function recoveryFailure(Node $node, RoleName $role, Throwable $exception): NodeRoleOperationException
    {
        return new NodeRoleOperationException(
            step: 'firewall-recovery',
            errorCode: 'node_role.remove_failed',
            underlyingErrorCode: 'node.firewall_recovery_failed',
            message: "Could not reopen public SSH on node [{$node->name}] after removing its last role [{$role->value}].",
            result: $exception instanceof FirewallOperationException ? $exception->result : null,
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
