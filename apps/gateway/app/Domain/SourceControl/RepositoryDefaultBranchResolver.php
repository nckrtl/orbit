<?php

declare(strict_types=1);

namespace App\Domain\SourceControl;

interface RepositoryDefaultBranchResolver
{
    public function resolve(string $repository): string;
}
