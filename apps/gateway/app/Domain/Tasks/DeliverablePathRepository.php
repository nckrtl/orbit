<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Project;

interface DeliverablePathRepository
{
    public function defaultBranchCommit(Project $project): string;

    /** @return list<string> Complete repository-relative file paths at this exact commit. */
    public function files(Project $project, string $commit): array;
}
