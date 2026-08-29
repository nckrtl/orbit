<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

enum ProcessDoctorIssueCode: string implements DoctorIssueCode
{
    case RuntimeMissing = 'process.runtime_missing';
    case StateMismatch = 'process.state_mismatch';
    case InspectionFailed = 'process.inspection_failed';
    case NodeUnreachable = 'process.node_unreachable';

    public function code(): string
    {
        return $this->value;
    }

    public function family(): DoctorFamily
    {
        return DoctorFamily::Process;
    }
}
