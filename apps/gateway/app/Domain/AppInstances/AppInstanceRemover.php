<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

use App\Models\AppInstance;
use App\Models\AppInstanceRemoval;

interface AppInstanceRemover
{
    public function execute(AppInstance $instance, bool $force): AppInstanceRemoval;
}
