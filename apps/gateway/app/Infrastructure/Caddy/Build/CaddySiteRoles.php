<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\NodeRole;
use Illuminate\Database\Eloquent\Builder;

/**
 * Decides which role rows serve their sites. A role serves while it converges or is active, and
 * after a failed convergence, because its sites may already be live. A role that is being removed,
 * or whose removal failed, serves nothing.
 */
final readonly class CaddySiteRoles
{
    /** Roles whose convergence installs Caddy and builds the Node, so the Node has a build even with no site yet. */
    public const array CaddyRoles = [
        RoleName::Gateway,
        RoleName::Router,
        RoleName::Ingress,
        RoleName::AppDev,
        RoleName::AppProd,
        RoleName::WebSocket,
        RoleName::Analytics,
    ];

    /**
     * Whether the Node should have Caddy: one of its Caddy roles is active or converging. Every Caddy role installs
     * Caddy before it builds the Node, so a Node whose Caddy roles all failed before that, or are being removed,
     * may have no Caddy, and then nothing on it has ever been served.
     */
    public static function nodeExpectsCaddy(int $nodeId): bool
    {
        return NodeRole::query()
            ->where('node_id', $nodeId)
            ->whereIn('role', array_map(static fn (RoleName $role): string => $role->value, self::CaddyRoles))
            ->whereIn('status', [LifecycleStatus::Active->value, LifecycleStatus::Provisioning->value])
            ->exists();
    }

    public static function serves(NodeRole $role): bool
    {
        return match ($role->status) {
            LifecycleStatus::Active, LifecycleStatus::Provisioning => true,
            LifecycleStatus::Failed => is_string($role->failed_step) && str_starts_with($role->failed_step, 'converge:'),
            LifecycleStatus::Removing => false,
        };
    }

    /** @return list<NodeRole> */
    public static function serving(RoleName $role, ?int $nodeId = null): array
    {
        return NodeRole::query()
            ->with('node')
            ->where('role', $role->value)
            ->when($nodeId !== null, static fn (Builder $query): Builder => $query->where('node_id', $nodeId))
            ->orderBy('id')
            ->get()
            ->filter(static fn (NodeRole $assignment): bool => self::serves($assignment))
            ->values()
            ->all();
    }

    public static function nodeServes(int $nodeId, RoleName $role): bool
    {
        return self::serving($role, $nodeId) !== [];
    }
}
