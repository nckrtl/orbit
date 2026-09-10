<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Models\Node;

final readonly class ManagedNodeEligibility
{
    public function allows(Node $node): bool
    {
        return
            $node->platform === 'linux'
            && is_string($node->wireguard_ip)
            && $node->wireguard_ip !== ''
            && is_string($node->ssh_host_fingerprint)
            && $node->ssh_host_fingerprint !== '';
    }
}
