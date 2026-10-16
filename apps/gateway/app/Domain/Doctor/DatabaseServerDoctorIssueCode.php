<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

enum DatabaseServerDoctorIssueCode: string implements DoctorIssueCode
{
    case Open = 'database_server.open';
    case AclFileMissing = 'database_server.acl_file_missing';
    case AclMismatch = 'database_server.acl_mismatch';
    case InspectionFailed = 'database_server.inspection_failed';

    public function code(): string
    {
        return $this->value;
    }

    public function family(): DoctorFamily
    {
        return DoctorFamily::DatabaseServer;
    }
}
