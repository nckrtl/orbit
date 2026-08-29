<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

enum WorkspaceDoctorIssueCode: string implements DoctorIssueCode
{
    case LifecycleNotActive = 'workspace.lifecycle_not_active';
    case CheckoutMissing = 'workspace.checkout_missing';
    case WorktreeMissing = 'workspace.worktree_missing';
    case BranchMismatch = 'workspace.branch_mismatch';
    case DocumentRootMissing = 'workspace.document_root_missing';
    case CaddyProjectionMismatch = 'workspace.caddy_projection_mismatch';
    case PhpFpmProjectionMismatch = 'workspace.php_fpm_projection_mismatch';
    case CertificateProjectionMismatch = 'workspace.certificate_projection_mismatch';
    case DnsProjectionMismatch = 'workspace.dns_projection_mismatch';
    case InspectionFailed = 'workspace.inspection_failed';
    case NodeUnreachable = 'workspace.node_unreachable';

    public function code(): string
    {
        return $this->value;
    }

    public function family(): DoctorFamily
    {
        return DoctorFamily::Workspace;
    }
}
