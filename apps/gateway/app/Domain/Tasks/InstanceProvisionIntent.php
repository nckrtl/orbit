<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Project;
use App\Models\TaskGroup;

/**
 * Intent a Project instance create path must honor.
 *
 * visitable=false: isolated Orbit monorepo checkout or worktree, no public URL.
 * visitable=true: a real App keeps its inspect subdomain.
 */
final readonly class InstanceProvisionIntent
{
    public function __construct(
        public TaskGroup $group,
        public bool $visitable,
    ) {}

    public static function for(TaskGroup $group): self
    {
        $group->loadMissing('project');

        return new self($group, self::visitableFor($group->project));
    }

    /** Orbit monorepo feature work (`orbit`) gets an isolated checkout; every other Project stays visitable. */
    public static function visitableFor(Project $project): bool
    {
        return $project->slug !== 'orbit';
    }
}
