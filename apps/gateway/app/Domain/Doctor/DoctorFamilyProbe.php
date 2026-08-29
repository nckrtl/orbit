<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Data\Doctor\DoctorFamilyReportData;

interface DoctorFamilyProbe
{
    public function family(): DoctorFamily;

    public function inspect(DoctorNodeContext $context): DoctorFamilyReportData;
}
