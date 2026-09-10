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
        if ($node->status !== LifecycleStatus::Active) {
            return false;
        }

        if (! $this->hasManagedNetworkIdentity($node)) {
            return false;
        }

        if ($this->hasPinnedSshIdentity($node)) {
            return true;
        }

        return $this->hasRoleWithStatus(
            $node,
            [LifecycleStatus::Provisioning, LifecycleStatus::Active],
        );
    }

    public function isManagedForObservation(Node $node): bool
    {
        if (! $this->hasManagedNetworkIdentity($node)) {
            return false;
        }

        if ($this->hasPinnedSshIdentity($node)) {
            return true;
        }

        if ($node->relationLoaded('roles')) {
            return $node->roles->isNotEmpty();
        }

        return $node->roles()->exists();
    }

    private function hasManagedNetworkIdentity(Node $node): bool
    {
        return $node->platform === 'linux'
            && is_string($node->wireguard_ip)
            && $node->wireguard_ip !== '';
    }

    private function hasPinnedSshIdentity(Node $node): bool
    {
        return is_string($node->ssh_host_fingerprint)
            && $node->ssh_host_fingerprint !== '';
    }

    /** @param list<LifecycleStatus> $statuses */
    private function hasRoleWithStatus(Node $node, array $statuses): bool
    {
        if ($node->relationLoaded('roles')) {
            return $node->roles->contains(
                static fn (NodeRole $role): bool => in_array($role->status, $statuses, strict: true),
            );
        }

        return $node->roles()
            ->whereIn('status', array_map(
                static fn (LifecycleStatus $status): string => $status->value,
                $statuses,
            ))
            ->exists();
    }
}
