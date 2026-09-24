<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\NodeRole;
use Illuminate\Database\Eloquent\Builder;

/**
 * Decides which role rows serve their sites. A role serves while it converges or is active, and
 * after a failed reconvergence, because its sites were live before that attempt. A role that is
 * being removed, or that failed before it ever became active, serves nothing.
 */
final readonly class CaddySiteRoles
{
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
