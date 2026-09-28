<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

use App\Models\AppInstanceRemoval;
use App\Models\Instance;

interface AppInstanceRemover
{
    public function execute(Instance $instance, bool $force): AppInstanceRemoval;
}
