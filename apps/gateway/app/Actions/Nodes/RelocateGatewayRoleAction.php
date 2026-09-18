<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Data\Nodes\NodeData;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\NodeRoleValidationException;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\RoleRegistry;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class RelocateGatewayRoleAction
{
    public function __construct(
        private AssignRoleAction $assignRole,
        private RoleRegistry $registry,
        private NodeRoleFirewallManager $firewall,
        private PrivateDnsManager $dns,
        private ?RecordEventBroadcaster $broadcaster = null,
    ) {}

    public function execute(Node $target, RoleName $role, bool $force = false): NodeRole
    {
        if ($role !== RoleName::Gateway) {
            throw new NodeRoleValidationException(
                message: "Role [{$role->value}] cannot be relocated.",
                details: ['field' => 'role', 'role' => $role->value],
            );
        }

        if (! $this->registry->definition($role)->mutable) {
            throw new NodeRoleValidationException(
                message: "Role [{$role->value}] is protected from generic mutation.",
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

        try {
            $this->assignRole->preflightTransfer($target, $role);
        } catch (RoleAssignmentException $exception) {
            throw new NodeRoleValidationException(
                message: $exception->getMessage(),
                details: ['field' => 'role', 'role' => $role->value],
            );
        }

        $source = $this->currentHolder($target);
        $this->firewall->converge($target, $role, $target->user);
        $assignment = $this->transfer($target, $source);
        $this->dns->converge();
        $this->firewall->remove($source, $role, $source->user);
        $this->announce($source);
        $this->announce($target);

        return $assignment;
    }

    private function guardActiveNode(Node $node): void
    {
        if (! $node->exists || $node->status !== LifecycleStatus::Active) {
            throw new NodeRoleValidationException('Roles can be changed only on an active node.');
        }
    }

    private function currentHolder(Node $target): Node
    {
        $assignment = NodeRole::query()
            ->with('node')
            ->where('role', RoleName::Gateway)
            ->first();

        if (! $assignment instanceof NodeRole || ! $assignment->node instanceof Node) {
            throw new NodeRoleValidationException(
                message: 'Role [gateway] is not assigned.',
                details: ['field' => 'role', 'role' => RoleName::Gateway->value],
            );
        }

        if ($assignment->node_id === $target->id) {
            throw new NodeRoleValidationException(
                message: "Role [gateway] is already assigned to node [{$target->name}].",
                details: ['field' => 'role', 'role' => RoleName::Gateway->value],
            );
        }

        if ($assignment->status !== LifecycleStatus::Active) {
            throw new NodeRoleValidationException(
                message: "Role [gateway] cannot relocate from status [{$assignment->status->value}].",
                details: ['field' => 'role', 'role' => RoleName::Gateway->value],
            );
        }

        return $assignment->node;
    }

    private function transfer(Node $target, Node $source): NodeRole
    {
        /**
         * @var NodeRole $assignment
         */
        $assignment = DB::transaction(function () use ($target, $source): NodeRole {
            $this->lockRoleClaims();
            $current = NodeRole::query()
                ->where('role', RoleName::Gateway)
                ->lockForUpdate()
                ->first();

            if (! $current instanceof NodeRole || $current->node_id !== $source->id) {
                throw new NodeRoleValidationException(
                    message: 'Role [gateway] assignment changed during relocation.',
                    details: ['field' => 'role', 'role' => RoleName::Gateway->value],
                );
            }

            $this->assignRole->preflightTransfer($target, RoleName::Gateway);
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
