<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Models\Instance;

interface InstanceStateInspector
{
    public function inspect(Instance $instance): InstanceInspectionData;
}
