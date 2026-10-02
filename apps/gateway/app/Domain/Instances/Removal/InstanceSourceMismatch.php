<?php

declare(strict_types=1);

namespace App\Domain\Instances\Removal;

use App\Models\Instance;

/**
 * One development source identity check that Instance removal never waives.
 *
 * The backed value is the bounded error code that names the refused check.
 */
enum InstanceSourceMismatch: string
{
    case Path = 'instance.source_path_mismatch';
    case Ownership = 'instance.source_ownership_mismatch';
    case Layout = 'instance.source_layout_mismatch';
    case Origin = 'instance.source_origin_mismatch';
    case Branch = 'instance.source_branch_mismatch';
    case Worktrees = 'instance.source_worktrees_mismatch';

    public function describe(string $instanceName): string
    {
        return "Instance [{$instanceName}] ".match ($this) {
            self::Path => 'source path is not the recorded Orbit-owned directory.',
            self::Ownership => 'source does not match the recorded managed ownership.',
            self::Layout => 'source Git layout does not match the recorded source layout.',
            self::Origin => 'source origin does not match the Project repository.',
            self::Branch => 'source branch does not match the recorded branch.',
            self::Worktrees => 'source linked-worktree inventory does not match the recorded checkout.',
        };
    }
}
