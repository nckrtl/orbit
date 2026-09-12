<?php

declare(strict_types=1);

namespace App\Domain\WireGuard;

use App\Models\Node;

interface WireGuardPeerDnsRepairer
{
    public function repair(Node $node): void;
}
