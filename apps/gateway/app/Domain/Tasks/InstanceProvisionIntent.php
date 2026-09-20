<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

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
}
