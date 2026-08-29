<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

enum DoctorFamilyStatus: string
{
    case Healthy = 'healthy';
    case Drift = 'drift';
    case Unverifiable = 'unverifiable';
}
