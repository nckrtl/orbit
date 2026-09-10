<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

use App\Models\AppInstance;

interface ProductionReleaseLayout
{
    public function validateCurrent(AppInstance $appInstance): void;

    public function clearCurrent(AppInstance $appInstance): void;
}
