<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

enum InstanceDoctorIssueCode: string implements DoctorIssueCode
{
    case LifecycleNotActive = 'instance.lifecycle_not_active';
    case SourceLayoutMismatch = 'instance.source_layout_mismatch';
    case CheckoutMissing = 'instance.checkout_missing';
    case RepositoryLayoutMismatch = 'instance.repository_layout_mismatch';
    case MigrationRequired = 'instance.migration_required';
    case OriginMismatch = 'instance.origin_mismatch';
    case SourceIdentityMismatch = 'instance.source_identity_mismatch';
    case ProductionHomeMismatch = 'instance.production_home_mismatch';
    case ReleaseSelectionMismatch = 'instance.release_selection_mismatch';
    case SelectedReleaseRootMismatch = 'instance.selected_release_root_mismatch';
    case EnvironmentProjectionMismatch = 'instance.environment_projection_mismatch';
    case PhpFpmAssociationMissing = 'instance.php_fpm_association_missing';
    case PhpFpmAssociationShared = 'instance.php_fpm_association_shared';
    case PhpFpmProjectionMismatch = 'instance.php_fpm_projection_mismatch';
    case CaddyProjectionMismatch = 'instance.caddy_projection_mismatch';
    case PublicIngressMismatch = 'instance.public_ingress_mismatch';
    case PrivateForwardingMismatch = 'instance.private_forwarding_mismatch';
    case PublicTlsMismatch = 'instance.public_tls_mismatch';
    case PublicFirewallMismatch = 'instance.public_firewall_mismatch';
    case RelatedNodeUnverifiable = 'instance.related_node_unverifiable';
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
