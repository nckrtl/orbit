<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Data\Nodes\NodeData;
use App\Domain\AppDev\PrivateDnsAnswerExpiry;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Metrics\MetricsReconcileDeferral;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\NodeRoleValidationException;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\RoleRegistry;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\NativeMetricsCredentialManager;
use App\Infrastructure\WebSocket\WebSocketFootprint;
use App\Models\Node;
use App\Models\NodeRole;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class RelocateNodeRoleAction
{
    public function __construct(
        private AssignRoleAction $assignRole,
        private RoleRegistry $registry,
        private NodeRoleFirewallManager $firewall,
        private PrivateDnsManager $dns,
        private GrantGatewayRoleAccessAction $access,
        private RoleBaselineConverger $baselines,
        private SettingRepository $settings,
        private ?RecordEventBroadcaster $broadcaster = null,
        private PrivateDnsAnswerExpiry $dnsAnswers = new PrivateDnsAnswerExpiry,
        private ?MetricsFleetReconciler $metrics = null,
        private ?MetricsReconcileDeferral $metricsDeferral = null,
    ) {}

    public function execute(Node $target, RoleName $role, bool $force = false, ?Node $from = null): NodeRole
    {
        if (! $this->registry->definition($role)->relocatable) {
            throw new NodeRoleValidationException(
                message: "Role [{$role->value}] cannot be relocated.",
                details: ['field' => 'role', 'role' => $role->value],
            );
        }

        if (! $force) {
            throw new NodeRoleValidationException(
                message: 'Use --force to relocate this node role.',
                details: [
                    'field' => 'force',
                    'reason' => 'destructive_consent_required',
                    'role' => $role->value,
                    'dependents' => [],
                ],
            );
        }

        $this->guardActiveNode($target);

        if ($from instanceof Node) {
            $this->guardActiveNode($from);

            if ($from->is($target)) {
                throw new NodeRoleValidationException(
                    message: "Role [{$role->value}] cannot relocate from node [{$from->name}] onto itself.",
                    details: ['field' => 'from', 'role' => $role->value],
                );
            }
        }

        $holder = $this->holderAssignment($role);
        $holderNode = $holder->node;

        if ($holderNode->is($target)) {
            if (! $from instanceof Node) {
                throw new NodeRoleValidationException(
                    message: "Role [{$role->value}] is already assigned to node [{$target->name}].",
                    details: ['field' => 'role', 'role' => $role->value],
                );
            }

            return $this->reconcileLeftovers($target, $from, $role, $holder);
        }

        $source = $from ?? $holderNode;

        if ($from instanceof Node && ! $from->is($holderNode)) {
            throw new NodeRoleValidationException(
                message: "Role [{$role->value}] is assigned to node [{$holderNode->name}], not [{$from->name}].",
                details: ['field' => 'from', 'role' => $role->value],
            );
        }

        try {
            $this->assignRole->preflightTransfer($target, $role);
        } catch (RoleAssignmentException $exception) {
            throw new NodeRoleValidationException(
                message: $exception->getMessage(),
                details: ['field' => 'role', 'role' => $role->value],
            );
        }

        $this->prepareTarget($target, $role);
        $this->copyOwnedSettings($source, $target, $role);
        $assignment = $this->transfer($target, $source, $role);
        $this->finishOrReport($target, $source, $role, function () use ($target, $source, $assignment, $role): void {
            $this->moveOwnedState($target, $source, $assignment, $role);
        });
        $this->announce($source);
        $this->announce($target);

        return $assignment;
    }

    private function reconcileLeftovers(Node $target, Node $from, RoleName $role, NodeRole $assignment): NodeRole
    {
        $this->copyOwnedSettings($from, $target, $role);
        $this->prepareTarget($target, $role);
        $this->finishOrReport($target, $from, $role, function () use ($target, $from, $assignment, $role): void {
            $this->moveOwnedState($target, $from, $assignment, $role);
        });
        $this->announce($from);
        $this->announce($target);

        return $assignment->refresh();
    }

    private function guardActiveNode(Node $node): void
    {
        if (! $node->exists || $node->status !== LifecycleStatus::Active) {
            throw new NodeRoleValidationException('Roles can be changed only on an active node.');
        }
    }

    private function holderAssignment(RoleName $role): NodeRole
    {
        $assignment = NodeRole::query()
            ->with('node')
            ->where('role', $role)
            ->first();

        if (! $assignment instanceof NodeRole) {
            throw new NodeRoleValidationException(
                message: "Role [{$role->value}] is not assigned.",
                details: ['field' => 'role', 'role' => $role->value],
            );
        }

        if ($assignment->status !== LifecycleStatus::Active) {
            throw new NodeRoleValidationException(
                message: "Role [{$role->value}] cannot relocate from status [{$assignment->status->value}].",
                details: ['field' => 'role', 'role' => $role->value],
            );
        }

        return $assignment;
    }

    private function prepareTarget(Node $target, RoleName $role): void
    {
        if ($role !== RoleName::Gateway) {
            return;
        }

        $this->firewall->converge($target, $role, $target->user);
    }

    /**
     * Converges the destination and retracts the source, then reconciles Metrics once against the
     * finished move. Each role converge and removal would otherwise reconcile Metrics on its own, so a
     * slow Metrics Node could hold the source retraction behind a check that has nothing to do with it.
     */
    private function moveOwnedState(Node $target, Node $source, NodeRole $assignment, RoleName $role): void
    {
        $reconcileMetrics = ($this->metricsDeferral ?? app(MetricsReconcileDeferral::class))->during(
            function () use ($target, $source, $assignment, $role): void {
                $this->afterTransfer($target, $assignment, $role);
                $this->retractSource($source, $assignment, $role);
            },
        );

        if ($reconcileMetrics) {
            ($this->metrics ?? app(MetricsFleetReconciler::class))->reconcile();
        }
    }

    private function afterTransfer(Node $target, NodeRole $assignment, RoleName $role): void
    {
        if ($role === RoleName::Gateway) {
            $this->access->execute($target);
            $this->dns->converge();

            return;
        }

        try {
            $this->baselines->converge($target, $assignment);
        } catch (NodeRoleOperationException $exception) {
            throw self::namespaced($exception, 'converge:');
        }
    }

    /** Names a baseline step the way a role row records it: `converge:STEP` on the target, `remove:STEP` on the source. */
    private static function namespaced(NodeRoleOperationException $exception, string $prefix): NodeRoleOperationException
    {
        if (str_starts_with($exception->step, 'converge:') || str_starts_with($exception->step, 'remove:')) {
            return $exception;
        }

        return new NodeRoleOperationException(
            $prefix.$exception->step,
            $exception->errorCode,
            $exception->underlyingErrorCode,
            $exception->getMessage(),
            $exception->result,
            $exception,
        );
    }

    /**
     * The assignment already names the target. When converging the target or withdrawing the source fails,
     * the move is incomplete: the error says so and names the command that finishes it. A step failure reports the
     * step's own error code, such as `metrics.exporter_firewall_ownership_drift`, with `converge:STEP` or
     * `remove:STEP`, because relocate records no failed step on a role row.
     * For `websocket`, the Gateway keeps serving both Reverb servers until then.
     *
     * @param  Closure(): void  $steps
     */
    private function finishOrReport(Node $target, Node $source, RoleName $role, Closure $steps): void
    {
        try {
            $steps();
        } catch (NodeRoleOperationException|ResourceOperationException $exception) {
            $message = "Role [{$role->value}] now runs on node [{$target->name}], but the move from node [{$source->name}] is incomplete: "
                .$exception->getMessage()
                ." Run `orbit node:role:relocate {$target->name} {$role->value} --from {$source->name} --force` to finish it once node [{$source->name}] is reachable.";

            throw $exception instanceof NodeRoleOperationException
                ? new NodeRoleOperationException($exception->step, $exception->underlyingErrorCode, $exception->underlyingErrorCode, $message, $exception->result, $exception)
                : new ResourceOperationException($exception->errorCode, $message, $exception->status, $exception, $exception->details);
        }
    }

    private function retractSource(Node $source, NodeRole $assignment, RoleName $role): void
    {
        if ($role === RoleName::WebSocket) {
            // The source still serves `reverb.orbit` until clients that resolved it before the target's
            // DNS publication can have resolved it again, as a Route move waits before it withdraws.
            $this->dnsAnswers->wait();
        }

        if ($role === RoleName::Gateway) {
            $this->firewall->remove($source, $role, $source->user);
            $this->forgetOwnedSettings($source, $role);

            return;
        }

        try {
            $this->baselines->remove($source, $this->ghostAssignment($source, $assignment), false);
        } catch (NodeRoleOperationException $exception) {
            throw self::namespaced($exception, 'remove:');
        }
        $this->forgetOwnedSettings($source, $role);
    }

    /**
     * The moved assignment as the source held it. It keeps the assignment id because the source's
     * runtime still carries it, for example on the Metrics containers.
     */
    private function ghostAssignment(Node $source, NodeRole $assignment): NodeRole
    {
        $ghost = new NodeRole([
            'node_id' => $source->id,
            'role' => $assignment->role,
            'status' => LifecycleStatus::Active,
        ]);
        $ghost->setAttribute('id', $assignment->id);
        $ghost->setRelation('node', $source);

        return $ghost;
    }

    private function copyOwnedSettings(Node $source, Node $target, RoleName $role): void
    {
        foreach ($this->ownedSettings($role) as [$key, $protection]) {
            $targetValue = $this->settings->get($this->scope($target), $key);

            if (is_string($targetValue) && $targetValue !== '') {
                continue;
            }

            $sourceValue = $this->settings->get($this->scope($source), $key);

            if (! is_string($sourceValue) || $sourceValue === '') {
                continue;
            }

            $this->settings->put($this->scope($target), $key, $sourceValue, $protection);
        }
    }

    private function forgetOwnedSettings(Node $source, RoleName $role): void
    {
        foreach ($this->ownedSettings($role) as [$key, $_]) {
            $this->settings->delete($this->scope($source), $key);
        }
    }

    /**
     * @return list<array{0: string, 1: SettingValueProtection}>
     */
    private function ownedSettings(RoleName $role): array
    {
        return match ($role) {
            RoleName::WebSocket => [
                [WebSocketFootprint::SettingKeyAppId, SettingValueProtection::Plain],
                [WebSocketFootprint::SettingKeyAppKey, SettingValueProtection::Plain],
                [WebSocketFootprint::SettingKeyAppSecret, SettingValueProtection::Secret],
                [WebSocketFootprint::SettingKeyAppKeyLaravel, SettingValueProtection::Secret],
            ],
            RoleName::Metrics => [
                [NativeMetricsCredentialManager::ActivePasswordKey, SettingValueProtection::Secret],
                [NativeMetricsCredentialManager::PendingPasswordKey, SettingValueProtection::Secret],
            ],
            default => [],
        };
    }

    private function scope(Node $node): SettingScope
    {
        return new SettingScope(SettingScopeType::Node, $node->id);
    }

    private function transfer(Node $target, Node $source, RoleName $role): NodeRole
    {
        /**
         * @var NodeRole $assignment
         */
        $assignment = DB::transaction(function () use ($target, $source, $role): NodeRole {
            $this->lockRoleClaims();
            $current = NodeRole::query()
                ->where('role', $role)
                ->lockForUpdate()
                ->first();

            if (! $current instanceof NodeRole || $current->node_id !== $source->id) {
                throw new NodeRoleValidationException(
                    message: "Role [{$role->value}] assignment changed during relocation.",
                    details: ['field' => 'role', 'role' => $role->value],
                );
            }

            $this->assignRole->preflightTransfer($target, $role);
            $current->update([
                'node_id' => $target->id,
                'status' => LifecycleStatus::Active,
                'failed_step' => null,
                'error_code' => null,
            ]);

            return $current->refresh();
        });

        return $assignment;
    }

    private function lockRoleClaims(): void
    {
        $affectedRows = DB::table('nodes')
            ->where('id', '=', static function (Builder $query): void {
                $query
                    ->from('nodes')
                    ->selectRaw('MIN(id)');
            })
            ->update(['id' => DB::raw('id')]);

        if ($affectedRows !== 1) {
            throw new RuntimeException('Could not acquire the node role claim lock.');
        }
    }

    private function announce(Node $node): void
    {
        ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
            RecordEventType::NodeUpdated,
            $node->id,
            NodeData::fromModel($node->refresh())->toArray(),
        );
    }
}
