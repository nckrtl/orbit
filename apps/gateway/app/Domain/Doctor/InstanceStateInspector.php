<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Models\AppInstance;

interface InstanceStateInspector
{
    public function inspect(AppInstance $appInstance): InstanceInspectionData;
}
