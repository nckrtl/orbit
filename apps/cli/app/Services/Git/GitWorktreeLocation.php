<?php

declare(strict_types=1);

namespace App\Services\Git;

final readonly class GitWorktreeLocation
{
    public function __construct(
        public string $topLevel,
        public string $defaultName,
    ) {}
}
