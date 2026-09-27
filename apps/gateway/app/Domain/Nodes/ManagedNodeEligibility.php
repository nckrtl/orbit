<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;

final readonly class ManagedNodeEligibility
{
    public function allows(Node $node): bool
    {
        return $node->status === LifecycleStatus::Active
            && $this->hasManagedNetworkIdentity($node)
            && $this->hasPinnedSshIdentity($node);
    }

    public function isManagedForObservation(Node $node): bool
    {
        return $this->hasManagedNetworkIdentity($node)
            && $this->hasPinnedSshIdentity($node);
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
}
