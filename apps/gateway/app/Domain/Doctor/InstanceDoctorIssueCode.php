<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

enum InstanceDoctorIssueCode: string implements DoctorIssueCode
{
    case LifecycleNotActive = 'instance.lifecycle_not_active';
    case CheckoutMissing = 'instance.checkout_missing';
    case DocumentRootMissing = 'instance.document_root_missing';
    case CaddyProjectionMismatch = 'instance.caddy_projection_mismatch';
    case PhpFpmProjectionMismatch = 'instance.php_fpm_projection_mismatch';
    case CertificateProjectionMismatch = 'instance.certificate_projection_mismatch';
    case DnsProjectionMismatch = 'instance.dns_projection_mismatch';
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
