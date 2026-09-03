<?php

declare(strict_types=1);

namespace App\Services\Git;

use RuntimeException;

final class GitWorktreeLocatorException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Run this command inside a Git worktree.');
    }
}
