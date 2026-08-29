<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

final class DoctorIssueCodeCatalog
{
    public static function fromInternal(DoctorFamily $family, string $code): DoctorIssueCode
    {
        return match ($family) {
            DoctorFamily::Node => NodeDoctorIssueCode::tryFrom($code) ?? NodeDoctorIssueCode::InspectionFailed,
            DoctorFamily::Role => RoleDoctorIssueCode::tryFrom($code) ?? RoleDoctorIssueCode::InspectionFailed,
            DoctorFamily::App => AppDoctorIssueCode::tryFrom($code) ?? AppDoctorIssueCode::InspectionFailed,
            DoctorFamily::Instance => InstanceDoctorIssueCode::tryFrom($code)
                ?? InstanceDoctorIssueCode::InspectionFailed,
            DoctorFamily::Workspace => WorkspaceDoctorIssueCode::tryFrom($code)
                ?? WorkspaceDoctorIssueCode::InspectionFailed,
            DoctorFamily::Tool => ToolDoctorIssueCode::tryFrom($code) ?? ToolDoctorIssueCode::InspectionFailed,
            DoctorFamily::Process => ProcessDoctorIssueCode::tryFrom($code) ?? ProcessDoctorIssueCode::InspectionFailed,
            DoctorFamily::Firewall => FirewallDoctorIssueCode::tryFrom($code)
                ?? FirewallDoctorIssueCode::InspectionFailed,
        };
    }
}
