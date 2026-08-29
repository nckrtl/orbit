<?php

declare(strict_types=1);

namespace App\Actions\Doctor;

use App\Data\Doctor\DoctorFamilyReportData;
use App\Data\Doctor\DoctorIssueData;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorFamilyProbe;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\DoctorIssueKind;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\InstanceDoctorIssueCode;
use App\Domain\Doctor\InstanceStateInspector;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Instance;

final readonly class InstanceDoctorProbe implements DoctorFamilyProbe
{
    public function __construct(
        private InstanceStateInspector $inspector,
    ) {}

    public function family(): DoctorFamily
    {
        return DoctorFamily::Instance;
    }

    public function inspect(DoctorNodeContext $context): DoctorFamilyReportData
    {
        $rows = Instance::query()->where('node_id', $context->node->id)->orderBy('id')->get();
        if ($rows->isEmpty()) {
            return DoctorFamilyReportData::fromIssues(DoctorFamily::Instance, 0, []);
        }
        if (! $context->inspection->reachable) {
            return DoctorFamilyReportData::fromIssues(DoctorFamily::Instance, $rows->count(), [new DoctorIssueData(
                InstanceDoctorIssueCode::NodeUnreachable,
                DoctorIssueKind::Unverifiable,
                'instance',
                null,
                null,
                'Instance state cannot be inspected because the node is unreachable.',
                'reachable',
                'unreachable',
            )]);
        }
        $issues = [];
        foreach ($rows as $instance) {
            if ($instance->status !== LifecycleStatus::Active) {
                $issues[] = new DoctorIssueData(
                    InstanceDoctorIssueCode::LifecycleNotActive,
                    DoctorIssueKind::Drift,
                    'instance',
                    $instance->id,
                    $instance->name,
                    'Instance lifecycle is not active.',
                    'active',
                    $instance->status->value,
                );
            }
            try {
                $observation = $this->inspector->inspect($instance);
                foreach ([
                    'checkoutExists' => InstanceDoctorIssueCode::CheckoutMissing,
                    'documentRootExists' => InstanceDoctorIssueCode::DocumentRootMissing,
                    'caddyProjectionMatches' => InstanceDoctorIssueCode::CaddyProjectionMismatch,
                    'phpFpmProjectionMatches' => InstanceDoctorIssueCode::PhpFpmProjectionMismatch,
                    'certificateProjectionMatches' => InstanceDoctorIssueCode::CertificateProjectionMismatch,
                    'dnsProjectionMatches' => InstanceDoctorIssueCode::DnsProjectionMismatch,
                ] as $field => $code) {
                    if ($observation->{$field} !== false) {
                        continue;
                    }
                    $issues[] = new DoctorIssueData(
                        $code,
                        DoctorIssueKind::Drift,
                        'instance',
                        $instance->id,
                        $instance->name,
                        'Instance projection does not match managed intent.',
                        'matching',
                        'mismatch',
                    );
                }
            } catch (DoctorInspectionException) {
                $issues[] = new DoctorIssueData(
                    InstanceDoctorIssueCode::InspectionFailed,
                    DoctorIssueKind::Unverifiable,
                    'instance',
                    $instance->id,
                    $instance->name,
                    'Instance inspection could not be verified.',
                    'verifiable',
                    'unverifiable',
                );
            }
        }

        return DoctorFamilyReportData::fromIssues(DoctorFamily::Instance, $rows->count(), $issues);
    }
}
