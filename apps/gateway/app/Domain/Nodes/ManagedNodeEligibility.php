<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\NodeRole;

final readonly class ManagedNodeEligibility
{
    public function allows(Node $node): bool
    {
        if (
            $node->status !== LifecycleStatus::Active
            || $node->platform !== 'linux'
            || ! is_string($node->wireguard_ip)
            || $node->wireguard_ip === ''
        ) {
            return false;
        }

        if (is_string($node->ssh_host_fingerprint) && $node->ssh_host_fingerprint !== '') {
            return true;
        }

        if ($node->relationLoaded('roles')) {
            return $node->roles->contains(
                static fn (NodeRole $role): bool => in_array(
                    $role->status,
                    [LifecycleStatus::Provisioning, LifecycleStatus::Active],
                    strict: true,
                ),
            );
        }

        return $node->roles()
            ->whereIn('status', [LifecycleStatus::Provisioning->value, LifecycleStatus::Active->value])
            ->exists();
    }
}
