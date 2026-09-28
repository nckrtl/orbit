<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

enum ProjectDoctorIssueCode: string implements DoctorIssueCode
{
    case RepositoryOriginMismatch = 'project.repository_origin_mismatch';
    case InspectionFailed = 'project.inspection_failed';
    case NodeUnreachable = 'project.node_unreachable';

    public function code(): string
    {
        return $this->value;
    }

    public function family(): DoctorFamily
    {
        return DoctorFamily::Project;
    }
}
