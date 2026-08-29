<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

enum FirewallDoctorIssueCode: string implements DoctorIssueCode
{
    case LifecycleNotActive = 'firewall.lifecycle_not_active';
    case BackendInactive = 'firewall.backend_inactive';
    case RuleMissing = 'firewall.rule_missing';
    case RuleMismatch = 'firewall.rule_mismatch';
    case InspectionFailed = 'firewall.inspection_failed';
    case NodeUnreachable = 'firewall.node_unreachable';

    public function code(): string
    {
        return $this->value;
    }

    public function family(): DoctorFamily
    {
        return DoctorFamily::Firewall;
    }
}
