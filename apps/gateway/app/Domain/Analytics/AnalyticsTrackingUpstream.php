<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\NodeRole;

/** Where a tracking host sends Plausible's script and event paths: the active analytics role over WireGuard. */
final readonly class AnalyticsTrackingUpstream
{
    public static function node(): ?Node
    {
        $node = NodeRole::query()
            ->with('node')
            ->where('role', RoleName::Analytics->value)
            ->where('status', LifecycleStatus::Active->value)
            ->first()
            ?->node;

        return $node instanceof Node
            && $node->status === LifecycleStatus::Active
            && is_string($node->wireguard_ip)
            && $node->wireguard_ip !== ''
                ? $node
                : null;
    }

    public static function current(): ?string
    {
        $node = self::node();

        return $node instanceof Node ? "{$node->wireguard_ip}:".PlausibleProcess::PORT : null;
    }
}
