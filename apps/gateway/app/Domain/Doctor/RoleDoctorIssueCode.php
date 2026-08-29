<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

enum RoleDoctorIssueCode: string implements DoctorIssueCode
{
    case LifecycleNotActive = 'role.lifecycle_not_active';
    case AssignmentConflict = 'role.assignment_conflict';
    case SingletonConflict = 'role.singleton_conflict';
    case PackagesMissing = 'role.packages_missing';
    case ServicesInactive = 'role.services_inactive';
    case FirewallProjectionMismatch = 'role.firewall_projection_mismatch';
    case VpnInactive = 'role.vpn_inactive';
    case VpnProjectionMismatch = 'role.vpn_projection_mismatch';
    case DnsProjectionMismatch = 'role.dns_projection_mismatch';
    case InspectionFailed = 'role.inspection_failed';
    case NodeUnreachable = 'role.node_unreachable';

    public function code(): string
    {
        return $this->value;
    }

    public function family(): DoctorFamily
    {
        return DoctorFamily::Role;
    }
}
