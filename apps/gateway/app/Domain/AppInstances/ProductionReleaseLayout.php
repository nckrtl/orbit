<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

use App\Models\Instance;

interface ProductionReleaseLayout
{
    public function validateCurrent(Instance $appInstance): void;

    public function clearCurrent(Instance $appInstance): void;
}
