<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

use App\Models\Instance;

interface AppInstanceCloneCandidateInspector
{
    public function inspect(Instance $candidate, string $targetBranch): CloneCandidateSource;
}
