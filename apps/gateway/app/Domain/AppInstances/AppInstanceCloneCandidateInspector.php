<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

use App\Models\AppInstance;

interface AppInstanceCloneCandidateInspector
{
    public function inspect(AppInstance $candidate, string $targetBranch): CloneCandidateSource;
}
