<?php

declare(strict_types=1);

namespace App\Infrastructure\Tools;

use App\Domain\Nodes\NodeRoleToolIntentGuard;
use App\Domain\Nodes\NodeRoleValidationException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolManagerName;
use App\Models\Node;

final readonly class EloquentNodeRoleToolIntentGuard implements NodeRoleToolIntentGuard
{
    private const int PREVIEW_LIMIT = 10;

    public function preview(Node $node, RoleName $role): array
    {
        if (! $this->isLastAppRole($node, $role)) {
            return [];
        }

        $blockingTools = $this->blockingTools($node);

        if ($blockingTools !== []) {
            return $blockingTools;
        }

        return ['VP and Composer become unavailable after role removal.'];
    }

    public function assertSafe(Node $node, RoleName $role): void
    {
        if (! $this->isLastAppRole($node, $role)) {
            return;
        }

        $dependents = $this->blockingTools($node);

        if ($dependents === []) {
            return;
        }

        throw new NodeRoleValidationException(
            'Remove app-scoped tools before removing the last active app role.',
            [
                'field' => 'role',
                'reason' => 'tool_removal_required',
                'role' => $role->value,
                'dependents' => $dependents,
            ],
        );
    }

    public function retireUnsupported(Node $node): void
    {
        if ($this->hasActiveAppRole($node)) {
            return;
        }

        $node
            ->toolManagers()
            ->whereIn('name', [ToolManagerName::Vp->value, ToolManagerName::Composer->value])
            ->update([
                'status' => LifecycleStatus::Failed,
                'failed_step' => 'app-role',
                'error_code' => 'tool_manager.app_role_required',
            ]);
    }

    /** @return list<string> */
    private function blockingTools(Node $node): array
    {
        $managerIds = $node
            ->toolManagers()
            ->whereIn('name', [ToolManagerName::Vp->value, ToolManagerName::Composer->value])
            ->pluck('id');

        $summaries = $node
            ->tools()
            ->where('protected', false)
            ->whereIn('tool_manager_id', $managerIds)
            ->with('manager')
            ->get()
            ->map(static fn ($tool): string => "{$tool->manager->name->value}:{$tool->package}")
            ->sort()
            ->take(self::PREVIEW_LIMIT)
            ->values()
            ->all();

        return array_values($summaries);
    }

    private function isLastAppRole(Node $node, RoleName $role): bool
    {
        return (
            in_array($role, [RoleName::AppDev, RoleName::AppProd], strict: true)
            && ! $node
                ->roles()
                ->whereIn('role', [RoleName::AppDev->value, RoleName::AppProd->value])
                ->where('role', '!=', $role->value)
                ->where('status', LifecycleStatus::Active)
                ->exists()
        );
    }

    private function hasActiveAppRole(Node $node): bool
    {
        return $node
            ->roles()
            ->whereIn('role', [RoleName::AppDev->value, RoleName::AppProd->value])
            ->where('status', LifecycleStatus::Active)
            ->exists();
    }
}
