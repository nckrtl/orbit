<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Nodes\NodeAgentRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskTopology;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Models\Node;
use App\Models\NodeRole;
use RuntimeException;

/** Runs inside the owned private Gateway after all recorded peers have been retargeted. */
final readonly class SandboxPairPrerequisites
{
    public function __construct(private NodeAgentRuntime $agents, private NodeCaddyBuilds $caddy, private PrivateDnsManager $dns) {}

    /** @param list<string> $inventory */
    public function converge(array $inventory): void
    {
        if (count(array_unique($inventory)) !== count($inventory)
            || array_diff(['gateway', 'operator'], $inventory) !== []
            || array_diff($inventory, ['gateway', 'operator', ...TaskTopology::Roles]) !== []) {
            throw new RuntimeException('Invalid isolated pair inventory.');
        }
        $nodes = Node::query()->with('roles')->get()->keyBy('name');
        if ($nodes->count() !== count($inventory) || array_diff($nodes->keys()->all(), $inventory) !== []) {
            throw new RuntimeException('Invalid isolated pair inventory.');
        }
        $addresses = ['gateway' => '10.44.0.1', 'operator' => '10.44.0.3', 'app-dev' => '10.44.0.2', 'app-prod' => '10.44.0.4', 'app-prod-2' => '10.44.0.5'];
        foreach ($inventory as $name) {
            $node = $nodes->get($name);
            $roles = match ($name) {
                'gateway' => ['gateway', 'vpn'],
                'operator' => [],
                'app-prod-2' => ['app-prod'],
                default => [$name],
            };
            if (! $node instanceof Node || $node->status !== LifecycleStatus::Active
                || $node->platform !== 'linux' || $node->user !== 'orbit' || $node->wireguard_ip !== $addresses[$name]
                || $node->roles->count() !== count($roles)
                || array_diff($roles, $node->roles->map(fn (NodeRole $role): string => $role->role->value)->all()) !== []
                || $node->roles->contains(fn (NodeRole $role): bool => $role->status !== LifecycleStatus::Active || ! in_array($role->role->value, $roles, true))) {
                throw new RuntimeException('Invalid isolated pair identity.');
            }
        }
        $gateway = $nodes->get('gateway');
        $operator = $nodes->get('operator');
        if (! $gateway instanceof Node || ! $operator instanceof Node) {
            throw new RuntimeException('Invalid isolated pair identity.');
        }
        $this->agents->converge($gateway);
        $this->agents->converge($operator);
        $this->caddy->build($gateway);
        $this->dns->converge();
    }
}
