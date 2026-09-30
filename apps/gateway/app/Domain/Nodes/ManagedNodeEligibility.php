<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;

/**
 * SSH management is a verified WireGuard address and a pinned SSH host key.
 * Linux agent, exporter, and subscription eligibility is a separate check.
 */
final readonly class ManagedNodeEligibility
{
    public function allows(Node $node): bool
    {
        return $node->status === LifecycleStatus::Active
            && $node->platform === 'linux'
            && $this->hasVerifiedSshManagement($node);
    }

    public function isManagedForObservation(Node $node): bool
    {
        return ($node->platform === 'linux' || $node->platform === 'macos')
            && $this->hasVerifiedSshManagement($node);
    }

    private function hasVerifiedSshManagement(Node $node): bool
    {
        return is_string($node->wireguard_ip)
            && $node->wireguard_ip !== ''
            && is_string($node->ssh_host_fingerprint)
            && $node->ssh_host_fingerprint !== '';
    }
}
