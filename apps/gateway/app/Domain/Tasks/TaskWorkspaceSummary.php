<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AppInstance;
use App\Models\TaskGroup;

/**
 * Handles a new commit or new diff counts that a Node agent reports for a task group's workspace
 * (ADR 0151). It stores the group's line counts when the diff is complete, and it always marks the
 * group for a `task_group.updated` notice, so a new commit reaches open tabs also when the diff is over
 * the agent's limits. Those tabs then show the group, which counts the lines over SSH.
 */
final readonly class TaskWorkspaceSummary
{
    public function __construct(private TaskBroadcasts $broadcasts) {}

    /**
     * @param  array{instance_id: int, base: string, start: ?string, branch: ?string, head: ?string, dirty: ?bool, commits: ?int, diff: array{files: int, added: int, removed: int, truncated: bool}|null}  $workspace
     */
    public function apply(int $nodeId, array $workspace): void
    {
        if (! AppInstance::query()->whereKey($workspace['instance_id'])->where('node_id', $nodeId)->exists()) {
            return;
        }

        $diff = $workspace['diff'];
        $groups = TaskGroup::query()
            ->with('app')
            ->where('taskable_type', 'instance')
            ->where('taskable_id', $workspace['instance_id'])
            ->whereIn('status', [...TaskGroupStatus::active(), TaskGroupStatus::Backlog, TaskGroupStatus::Todo])
            ->get();

        foreach ($groups as $group) {
            if ($group->app->default_branch !== $workspace['base']) {
                continue;
            }

            if ($diff !== null && ! $diff['truncated']) {
                $group->fill([
                    'lines_added' => $diff['added'],
                    'lines_deleted' => $diff['removed'],
                    'line_diff' => $diff['added'] + $diff['removed'],
                ]);

                if ($group->isDirty()) {
                    $group->save();
                }
            }

            $this->broadcasts->groupChanged($group->id);
        }
    }
}
