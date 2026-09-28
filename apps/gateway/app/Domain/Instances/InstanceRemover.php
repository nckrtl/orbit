<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\Instance;
use App\Models\InstanceRemoval;

interface InstanceRemover
{
    public function execute(Instance $instance, bool $force): InstanceRemoval;
}
