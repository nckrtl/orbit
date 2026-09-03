<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

enum AppInstanceSourceKind: string
{
    case ManagedClone = 'managed_clone';
    case RegisteredWorktree = 'registered_worktree';
}
