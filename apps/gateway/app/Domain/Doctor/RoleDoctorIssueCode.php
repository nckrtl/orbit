<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

enum RoleDoctorIssueCode: string implements DoctorIssueCode
{
    case LifecycleNotActive = 'role.lifecycle_not_active';
    case ClaimStale = 'role.claim_stale';
    case AssignmentConflict = 'role.assignment_conflict';
    case SingletonConflict = 'role.singleton_conflict';
    case ClusterOwnershipMismatch = 'role.cluster_ownership_mismatch';
    case ClusterCardinalityConflict = 'role.cluster_cardinality_conflict';
    case PackagesMissing = 'role.packages_missing';
    case CaddyVersionUnsupported = 'role.caddy_version_unsupported';
    case CaddyBuildDrift = 'role.caddy_build_drift';
    case ServicesInactive = 'role.services_inactive';
    case FirewallProjectionMismatch = 'role.firewall_projection_mismatch';
    case VpnInactive = 'role.vpn_inactive';
    case VpnProjectionMismatch = 'role.vpn_projection_mismatch';
    case DnsProjectionMismatch = 'role.dns_projection_mismatch';
    case DnsSnippetConflict = 'role.dns_snippet_conflict';
    case PrivateDnsRouteMismatch = 'role.private_dns_route_mismatch';
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
