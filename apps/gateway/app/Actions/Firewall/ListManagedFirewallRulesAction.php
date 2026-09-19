<?php

declare(strict_types=1);

namespace App\Actions\Firewall;

use App\Domain\Firewall\FirewallOperationException;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Firewall\UfwManagedRule;
use App\Models\Node;
use App\Models\NodeRole;

final readonly class ListManagedFirewallRulesAction
{
    public function __construct(private NodeFirewallRuleCatalog $catalog) {}

    /**
     * The rules Orbit itself keeps on the Node: the ones every managed Node has, then the ones each
     * active role needs. They come from the same catalog that convergence applies, so this is what
     * Orbit intends, not a reading of the Node; Doctor compares the two.
     *
     * @return list<array{name: string, role: ?string, action: string, source: string, destination: string, port: string, protocol: string, interface: ?string}>
     */
    public function execute(Node $node): array
    {
        $rules = $this->rows(null, fn (): array => $this->catalog->forNode($node));

        $assignments = $node->roles()
            ->where('status', LifecycleStatus::Active)
            ->orderBy('role')
            ->get();

        foreach ($assignments as $assignment) {
            /** @var NodeRole $assignment */
            $rules = [
                ...$rules,
                ...$this->rows(
                    $assignment->role->value,
                    fn (): array => $this->catalog->forRole($node, $assignment->role),
                ),
            ];
        }

        return $rules;
    }

    /**
     * @param  callable(): list<UfwManagedRule>  $rules
     * @return list<array{name: string, role: ?string, action: string, source: string, destination: string, port: string, protocol: string, interface: ?string}>
     */
    private function rows(?string $role, callable $rules): array
    {
        try {
            $managed = $rules();
        } catch (FirewallOperationException) {
            // A Node without a WireGuard address has no scoped rules to intend yet.
            return [];
        }

        return array_map(static fn (UfwManagedRule $rule): array => [
            'name' => $rule->shape->comment,
            'role' => $role,
            'action' => $rule->shape->action,
            'source' => $rule->shape->source,
            'destination' => $rule->shape->destination,
            'port' => $rule->shape->port,
            'protocol' => $rule->shape->protocol,
            'interface' => $rule->shape->inInterface,
        ], $managed);
    }
}
