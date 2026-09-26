<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\AgentWorkspaceData;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\TaskGroup;

/**
 * The task checkouts on one Node that its agent watches: the Instances that hold the workspace of a
 * task group that has not finished. Empty while the tasks extension is disabled (ADR 0151).
 */
final readonly class ListAgentWorkspacesAction
{
    public const int Limit = 64;

    public function __construct(private TaskExtensionState $extension) {}

    /** @return list<AgentWorkspaceData> */
    public function execute(Node $node): array
    {
        if (! $this->extension->enabled()) {
            return [];
        }

        $unfinished = [
            TaskGroupStatus::Backlog, TaskGroupStatus::Todo, TaskGroupStatus::Reserved,
            TaskGroupStatus::Running, TaskGroupStatus::Reviewing, TaskGroupStatus::Settling,
        ];
        $groups = TaskGroup::query()
            ->with(['app', 'taskable'])
            ->where('taskable_type', 'instance')
            ->whereNotNull('taskable_id')
            ->whereIn('status', $unfinished)
            ->whereIn('taskable_id', AppInstance::query()->select('id')->where('node_id', $node->getKey()))
            ->orderBy('id')
            ->get();
        $workspaces = [];

        foreach ($groups as $group) {
            $instance = $group->taskable;
            $base = $group->app->default_branch;

            if (! $instance instanceof AppInstance || $instance->checkout_path === '' || ! is_string($base) || $base === '' || isset($workspaces[$instance->id])) {
                continue;
            }

            $start = $instance->starting_commit;
            $workspaces[$instance->id] = new AgentWorkspaceData(
                instanceId: $instance->id,
                path: $instance->checkout_path,
                base: $base,
                start: is_string($start) && preg_match('/\A[0-9a-f]{40}\z/D', $start) === 1 ? $start : null,
            );

            if (count($workspaces) === self::Limit) {
                break;
            }
        }

        return array_values($workspaces);
    }
}
