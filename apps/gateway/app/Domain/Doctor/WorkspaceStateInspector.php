<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Models\Workspace;

interface WorkspaceStateInspector
{
    public function inspect(Workspace $workspace): WorkspaceInspectionData;
}
