<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmPlacement;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\TaskVm;

/**
 * Which Nodes the fleet rollout visits, and in which order (ADR 0202).
 *
 * A Node is in the rollout when all of these hold:
 *
 * - it is not a disposable task sandbox: it has no `compute_sandbox_id` and is not a live task VM (ADR 0200);
 * - it is `active` and runs Linux;
 * - the Gateway manages it over SSH: it has a WireGuard address and a pinned SSH host key;
 * - it holds at least one active role other than `gateway`;
 * - it is not the Gateway's own machine: it holds no `gateway` role and is not the serving host;
 * - its `/usr/local/bin/orbit` is not a CLI Orbit did not install ({@see NodeCliState}).
 *
 * Roleless Nodes, such as operator machines, and macOS Nodes are never visited. They update
 * themselves with `orbit self-update`. A task sandbox gets its agent and footprint when it is
 * provisioned and is destroyed with its task group, so neither the rollout nor its catch-up visits it.
 *
 * The rollout goes lowest risk first. Each role belongs to a group, and a Node with several roles
 * goes in the latest group of its roles, so it waits until every lower-risk Node is done:
 *
 * 1. `app-dev` (and `agent`)
 * 2. `metrics` and `analytics` (and `s3`)
 * 3. `database`, `websocket`, `vpn`, and `router`
 * 4. `app-prod` and `ingress`
 *
 * Inside a group the Node ID decides. `ORBIT_FLEET_ROLLOUT_ORDER` overrides the order: the Nodes it
 * names, by name and comma-separated, go first in that order, and the others follow in the default
 * order. The override never adds or removes a Node.
 */
final readonly class FleetRolloutMembership
{
    /** @var array<string, int> */
    public const array Groups = [
        RoleName::AppDev->value => 1,
        'agent' => 1,
        RoleName::Metrics->value => 2,
        RoleName::Analytics->value => 2,
        's3' => 2,
        RoleName::Database->value => 3,
        RoleName::WebSocket->value => 3,
        RoleName::Vpn->value => 3,
        RoleName::Router->value => 3,
        RoleName::AppProd->value => 4,
        RoleName::Ingress->value => 4,
    ];

    /**
     * @param  list<string>  $order  Node names that go first, in this order.
     */
    public function __construct(
        private ManagedNodeEligibility $eligibility,
        private GatewayServingHost $servingHost,
        private array $order = [],
        private NodeCliState $cli = new NodeCliState,
    ) {}

    /**
     * The rollout set in rollout order.
     *
     * @return list<Node>
     */
    public function members(): array
    {
        $members = Node::query()
            ->with('roles')
            ->where('status', LifecycleStatus::Active)
            ->where('platform', 'linux')
            ->orderBy('id')
            ->get()
            ->filter(fn (Node $node): bool => $this->exclusion($node) === null)
            ->values()
            ->all();

        $pinned = array_flip($this->order);
        usort($members, fn (Node $left, Node $right): int => [
            $pinned[$left->name] ?? PHP_INT_MAX,
            $this->group($left),
            $left->id,
        ] <=> [
            $pinned[$right->name] ?? PHP_INT_MAX,
            $this->group($right),
            $right->id,
        ]);

        return $members;
    }

    public function includes(Node $node): bool
    {
        return $this->exclusion($node) === null;
    }

    /**
     * Why the rollout leaves the Node out, or null when it is in the rollout set.
     *
     * @return 'sandbox'|'inactive'|'platform'|'unmanaged'|'gateway'|'roleless'|'foreign_cli'|null
     */
    public function exclusion(Node $node): ?string
    {
        if ($node->compute_sandbox_id !== null || TaskVmPlacement::forNode($node) instanceof TaskVm) {
            return 'sandbox';
        }

        if ($node->status !== LifecycleStatus::Active) {
            return 'inactive';
        }

        if ($node->platform !== 'linux') {
            return 'platform';
        }

        if (! $this->eligibility->allows($node)) {
            return 'unmanaged';
        }

        $roles = $node->roles;

        if ($roles->contains(static fn (NodeRole $role): bool => $role->role === RoleName::Gateway) || $this->servingHost->is($node)) {
            return 'gateway';
        }

        if ($this->workloadRoles($node) === []) {
            return 'roleless';
        }

        return $this->cli->isForeign($node) ? 'foreign_cli' : null;
    }

    /** The Node's rollout group: the latest group of its active roles. */
    public function group(Node $node): int
    {
        $group = 1;

        foreach ($this->workloadRoles($node) as $role) {
            $group = max($group, self::Groups[$role] ?? 3);
        }

        return $group;
    }

    /** @return list<string> */
    private function workloadRoles(Node $node): array
    {
        return array_values($node->roles
            ->filter(static fn (NodeRole $role): bool => $role->status === LifecycleStatus::Active && $role->role !== RoleName::Gateway)
            ->map(static fn (NodeRole $role): string => $role->role->value)
            ->all());
    }
}
