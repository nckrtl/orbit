<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

use App\Models\AppInstance;
use App\Models\AppInstanceRemoval;

interface StagedAppInstanceRemover extends AppInstanceRemover
{
    /** Records and marks a removal without deleting the Instance source. */
    public function prepare(AppInstance $instance, bool $force): AppInstanceRemoval;
}
