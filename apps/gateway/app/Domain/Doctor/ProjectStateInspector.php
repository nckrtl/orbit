<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Models\Node;
use App\Models\Project;

interface ProjectStateInspector
{
    public function inspect(Project $project, Node $node): ProjectInspectionData;
}
