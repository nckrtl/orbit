<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Instance;
use App\Models\Project;

interface DeliverablePathRepository
{
    public function defaultBranchCommit(Project $project): string;

    /**
     * Reads the task workspace first when one is given, then the origin. Fails closed when neither has the commit.
     *
     * @return list<string> Complete repository-relative file paths at this exact commit.
     */
    public function files(Project $project, string $commit, ?Instance $workspace = null): array;
}
