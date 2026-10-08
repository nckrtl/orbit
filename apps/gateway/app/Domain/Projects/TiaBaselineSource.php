<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Models\Project;

interface TiaBaselineSource
{
    public function fetch(Project $project): TiaBaselineFiles;
}
