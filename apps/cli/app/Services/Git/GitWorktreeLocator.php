<?php

declare(strict_types=1);

namespace App\Services\Git;

interface GitWorktreeLocator
{
    public function locate(): GitWorktreeLocation;
}
