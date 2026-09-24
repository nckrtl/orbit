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
    /**
     * `node:role:add NODE analytics --converge` marks the assignment provisioning while it republishes
     * private DNS and Caddy, so projections pass `$includeConverging` to keep tracking hosts through that
     * converge. An active holder wins. Product gates such as enabling analytics still need an active role.
     */
    public static function node(bool $includeConverging = false): ?Node
    {
        $statuses = $includeConverging
            ? [LifecycleStatus::Active, LifecycleStatus::Provisioning]
            : [LifecycleStatus::Active];
        $node = null;

        foreach ($statuses as $status) {
            $node ??= NodeRole::query()
                ->with('node')
                ->where('role', RoleName::Analytics->value)
                ->where('status', $status->value)
                ->first()
                ?->node;
        }

        return $node instanceof Node
            && $node->status === LifecycleStatus::Active
            && is_string($node->wireguard_ip)
            && $node->wireguard_ip !== ''
                ? $node
                : null;
    }

    public static function current(bool $includeConverging = false): ?string
    {
        $node = self::node($includeConverging);

        return $node instanceof Node ? "{$node->wireguard_ip}:".PlausibleProcess::PORT : null;
    }
}
