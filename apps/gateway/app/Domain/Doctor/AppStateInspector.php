<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Models\Node;
use App\Models\Project;

interface AppStateInspector
{
    public function inspect(Project $app, Node $node): AppInspectionData;
}
