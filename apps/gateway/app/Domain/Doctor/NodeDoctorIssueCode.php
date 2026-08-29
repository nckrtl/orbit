<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

enum NodeDoctorIssueCode: string implements DoctorIssueCode
{
    case LifecycleNotActive = 'node.lifecycle_not_active';
    case SshUnreachable = 'node.ssh_unreachable';
    case PlatformMismatch = 'node.platform_mismatch';
    case ArchitectureMismatch = 'node.architecture_mismatch';
    case WireGuardAddressMismatch = 'node.wireguard_address_mismatch';
    case InspectionFailed = 'node.inspection_failed';

    public function code(): string
    {
        return $this->value;
    }

    public function family(): DoctorFamily
    {
        return DoctorFamily::Node;
    }
}
