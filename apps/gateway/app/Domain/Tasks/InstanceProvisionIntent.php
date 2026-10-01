<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;

/**
 * Intent a Project instance create path must honor.
 *
 * visitable is the Project's task_workspace_routed setting for a new workspace.
 * The provisioner records that choice. Lifecycle and Doctor then use the record,
 * not the Project slug or a later change to the setting.
 */
final readonly class InstanceProvisionIntent
{
    public function __construct(
        public Task $group,
        public bool $visitable,
    ) {}

    public static function for(Task $group): self
    {
        $group->loadMissing('project');

        return new self($group, $group->project->task_workspace_routed);
    }
}
