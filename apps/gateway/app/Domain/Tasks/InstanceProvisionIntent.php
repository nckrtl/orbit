<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\TaskGroup;

/**
 * Intent an App instance create path must honor.
 *
 * visitable=false: isolated Orbit monorepo checkout or worktree, no public URL.
 * visitable=true: a real App keeps its inspect subdomain.
 * selfAccess=true: only a Node with access to itself fits, because a planner manages its group through Orbit from that Node.
 */
final readonly class InstanceProvisionIntent
{
    public function __construct(
        public TaskGroup $group,
        public bool $visitable,
        public bool $selfAccess = false,
    ) {}

    /** Orbit monorepo feature work (`orbit`) gets an isolated checkout; every other Project stays visitable. */
    public static function for(TaskGroup $group, bool $selfAccess = false): self
    {
        $group->loadMissing('app');

        return new self($group, $group->app->slug !== 'orbit', $selfAccess);
    }
}
