<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Models\Process;

interface ProcessStateInspector
{
    /** @throws DoctorInspectionException */
    public function inspect(Process $process): ProcessInspectionData;
}
