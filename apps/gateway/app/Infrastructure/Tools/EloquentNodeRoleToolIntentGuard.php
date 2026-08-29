<?php

declare(strict_types=1);

namespace App\Infrastructure\Tools;

use App\Domain\Nodes\NodeRoleToolIntentGuard;
use App\Domain\Nodes\NodeRoleValidationException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolManagerName;
use App\Models\Node;
use App\Models\Tool;

final readonly class EloquentNodeRoleToolIntentGuard implements NodeRoleToolIntentGuard
{
    private const int PREVIEW_LIMIT = 10;

    /** @return list<string> */
    public function preview(Node $node, RoleName $role): array
    {
        if (! in_array($role, [RoleName::AppDev, RoleName::AppProd], strict: true)) {
            return [];
        }

        if ($this->hasSupportedAppRoleOtherThan($node, $role)) {
            return [];
        }

        /**
         * @var list<string> $tools
         * @mago-expect lint:inline-variable-return The annotation narrows Laravel's Collection result to a list.
         */
        $tools = $node
            ->tools()
            ->join('tool_managers', 'tools.tool_manager_id', '=', 'tool_managers.id')
            ->where('protected', false)
            ->whereIn('tool_managers.name', [
                ToolManagerName::Vp->value,
                ToolManagerName::Composer->value,
            ])
            ->orderBy('tool_managers.name')
            ->orderBy('tools.package')
            ->orderBy('tools.id')
            ->limit(self::PREVIEW_LIMIT)
            ->get([
                'tools.package',
                'tool_managers.name as manager_name',
            ])
            ->map(static function (Tool $tool): string {
                /** @var string $managerName */
                $managerName = $tool->getAttribute('manager_name');

                return "{$managerName}:{$tool->package}";
            })
            ->all();

        return $tools;
    }

    public function assertRemovalSafe(Node $node, RoleName $role): void
    {
        $tools = $this->preview($node, $role);

        if ($tools === []) {
            return;
        }

        throw new NodeRoleValidationException(
            'Remove app-scoped Tools before removing the last active app role.',
            [
                'field' => 'role',
                'reason' => 'tool_removal_required',
                'role' => $role->value,
                'tools' => $tools,
            ],
        );
    }

    /** @return list<string> */
    public function retirementPreview(Node $node, RoleName $role): array
    {
        if (! in_array($role, [RoleName::AppDev, RoleName::AppProd], strict: true)) {
            return [];
        }

        if ($this->hasSupportedAppRoleOtherThan($node, $role)) {
            return [];
        }

        /**
         * @var list<string> $summaries
         * @mago-expect lint:inline-variable-return The annotation narrows Laravel's Collection result to a list.
         */
        $summaries = $node
            ->toolManagers()
            ->whereIn('name', [ToolManagerName::Vp->value, ToolManagerName::Composer->value])
            ->get(['name'])
            ->map(static fn ($manager): string => match ($manager->name) {
                ToolManagerName::Composer => 'Composer Tool manager will become unavailable',
                ToolManagerName::Vp => 'VP Tool manager will become unavailable',
                default => throw new \LogicException('Unexpected app-scoped Tool manager.'),
            })
            ->sort(SORT_STRING)
            ->values()
            ->all();

        return $summaries;
    }

    public function retireUnsupportedManagers(Node $node): void
    {
        $hasSupportedAppRole = $node
            ->roles()
            ->whereIn('role', [RoleName::AppDev->value, RoleName::AppProd->value])
            ->whereIn('status', [LifecycleStatus::Provisioning, LifecycleStatus::Active])
            ->exists();

        if ($hasSupportedAppRole) {
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

    private function hasSupportedAppRoleOtherThan(Node $node, RoleName $role): bool
    {
        return $node
            ->roles()
            ->whereIn('role', [RoleName::AppDev->value, RoleName::AppProd->value])
            ->where('role', '!=', $role->value)
            ->whereIn('status', [LifecycleStatus::Provisioning, LifecycleStatus::Active])
            ->exists();
    }
}
