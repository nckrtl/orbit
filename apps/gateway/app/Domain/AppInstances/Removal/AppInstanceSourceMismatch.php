<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Removal;

/**
 * One development source identity check that AppInstance removal never waives.
 *
 * The backed value is the bounded error code that names the refused check.
 */
enum AppInstanceSourceMismatch: string
{
    case Path = 'instance.source_path_mismatch';
    case Ownership = 'instance.source_ownership_mismatch';
    case Layout = 'instance.source_layout_mismatch';
    case Origin = 'instance.source_origin_mismatch';
    case Branch = 'instance.source_branch_mismatch';
    case Worktrees = 'instance.source_worktrees_mismatch';

    public function describe(string $appInstanceName): string
    {
        return "AppInstance [{$appInstanceName}] ".match ($this) {
            self::Path => 'source path is not the recorded Orbit-owned directory.',
            self::Ownership => 'source is not owned by the managed Node account.',
            self::Layout => 'source Git layout does not match the recorded source layout.',
            self::Origin => 'source origin does not match the App repository.',
            self::Branch => 'source branch does not match the recorded branch.',
            self::Worktrees => 'source linked-worktree inventory does not match the recorded checkout.',
        };
    }
}
