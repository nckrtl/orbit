<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

enum DoctorIssueKind: string
{
    case Informational = 'informational';
    case Drift = 'drift';
    case Unverifiable = 'unverifiable';
}
