<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

enum AppDoctorIssueCode: string implements DoctorIssueCode
{
    case RepositoryOriginMismatch = 'app.repository_origin_mismatch';
    case InspectionFailed = 'app.inspection_failed';
    case NodeUnreachable = 'app.node_unreachable';

    public function code(): string
    {
        return $this->value;
    }

    public function family(): DoctorFamily
    {
        return DoctorFamily::App;
    }
}
