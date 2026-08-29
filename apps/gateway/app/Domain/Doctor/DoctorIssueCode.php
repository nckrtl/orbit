<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

interface DoctorIssueCode
{
    public function code(): string;

    public function family(): DoctorFamily;
}
