<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

enum DatabaseConnectionDoctorIssueCode: string implements DoctorIssueCode
{
    case Missing = 'database_connection.missing';
    case Unhealthy = 'database_connection.unhealthy';
    case EnvMismatch = 'database_connection.env_mismatch';
    case InspectionFailed = 'database_connection.inspection_failed';

    public function code(): string
    {
        return $this->value;
    }

    public function family(): DoctorFamily
    {
        return DoctorFamily::DatabaseConnection;
    }
}
