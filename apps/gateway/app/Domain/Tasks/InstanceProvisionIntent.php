<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Project;
use App\Models\TaskGroup;

/**
 * Intent an App instance create path must honor.
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
        $group->loadMissing('app');

        return new self($group, self::visitableFor($group->app));
    }

    /** Orbit monorepo feature work (`orbit`) gets an isolated checkout; every other Project stays visitable. */
    public static function visitableFor(Project $app): bool
    {
        return $app->slug !== 'orbit';
    }
}
