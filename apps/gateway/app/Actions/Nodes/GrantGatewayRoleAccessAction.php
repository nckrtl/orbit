<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;

final readonly class GrantGatewayRoleAccessAction
{
    /** @var list<RoleName> */
    public const array GrantedRoles = [RoleName::Vpn, RoleName::Metrics];

    public function __construct(
        private AddNodeAccessAction $access,
    ) {}

    /**
     * Grant the Gateway role holder directed access to the vpn and metrics nodes.
     *
     * @return list<Node>
     */
    public function execute(Node $gateway): array
    {
        $granted = [];

        foreach (self::GrantedRoles as $role) {
            $serving = $this->activeRoleHolder($role);

            if (! $serving instanceof Node || $serving->is($gateway)) {
                continue;
            }

            $this->access->execute($gateway, $serving);
            $granted[] = $serving;
        }

        return $granted;
    }

    private function activeRoleHolder(RoleName $role): ?Node
    {
        return Node::query()
            ->where('status', LifecycleStatus::Active)
            ->whereHas(
                'roles',
                static fn ($query) => $query
                    ->where('role', $role)
                    ->where('status', LifecycleStatus::Active),
            )
            ->first();
    }
}
