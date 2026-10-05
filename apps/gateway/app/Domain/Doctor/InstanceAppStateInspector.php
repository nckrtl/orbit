<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Models\Instance;

interface InstanceAppStateInspector
{
    public function inspectApp(Instance $instance, string $app): InstanceAppInspectionData;
}
