<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\Instance;

interface DevelopmentInstanceBranchInspector
{
    public function assertBranchCheckedOut(Instance $instance, ?string $branch): void;
}
