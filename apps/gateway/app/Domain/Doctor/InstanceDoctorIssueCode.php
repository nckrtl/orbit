<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

enum InstanceDoctorIssueCode: string implements DoctorIssueCode
{
    case LifecycleNotActive = 'instance.lifecycle_not_active';
    case CheckoutMissing = 'instance.checkout_missing';
    case RepositoryNotIndependent = 'instance.repository_not_independent';
    case OriginMismatch = 'instance.origin_mismatch';
    case SourceIdentityMismatch = 'instance.source_identity_mismatch';
    case RegisteredWorktreeUnavailable = 'instance.registered_worktree_unavailable';
    case InspectionFailed = 'instance.inspection_failed';
    case NodeUnreachable = 'instance.node_unreachable';

    public function code(): string
    {
        return $this->value;
    }

    public function family(): DoctorFamily
    {
        return DoctorFamily::Instance;
    }
}
