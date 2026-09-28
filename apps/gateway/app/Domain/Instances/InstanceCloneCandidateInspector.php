<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\Instance;

interface InstanceCloneCandidateInspector
{
    public function inspect(Instance $candidate, string $targetBranch): CloneCandidateSource;
}
