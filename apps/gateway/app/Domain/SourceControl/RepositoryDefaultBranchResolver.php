<?php

declare(strict_types=1);

namespace App\Domain\SourceControl;

use App\Domain\Projects\ProjectSourceAccess;

interface RepositoryDefaultBranchResolver
{
    public function resolve(string $repository, ProjectSourceAccess $source): string;

    public function verify(string $repository, string $branch, ProjectSourceAccess $source): void;
}
