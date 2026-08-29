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
use App\Domain\Doctor\WorkspaceDoctorIssueCode;
use App\Domain\Doctor\WorkspaceStateInspector;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Workspace;

final readonly class WorkspaceDoctorProbe implements DoctorFamilyProbe
{
    public function __construct(
        private WorkspaceStateInspector $inspector,
    ) {}

    public function family(): DoctorFamily
    {
        return DoctorFamily::Workspace;
    }

    public function inspect(DoctorNodeContext $context): DoctorFamilyReportData
    {
        $rows = Workspace::query()
            ->whereHas('instance', static fn ($query) => $query->where('node_id', $context->node->id))
            ->orderBy('id')
            ->get();
        if ($rows->isEmpty()) {
            return DoctorFamilyReportData::fromIssues(DoctorFamily::Workspace, 0, []);
        }
        if (! $context->inspection->reachable) {
            return DoctorFamilyReportData::fromIssues(DoctorFamily::Workspace, $rows->count(), [new DoctorIssueData(
                WorkspaceDoctorIssueCode::NodeUnreachable,
                DoctorIssueKind::Unverifiable,
                'workspace',
                null,
                null,
                'Workspace state cannot be inspected because the node is unreachable.',
                'reachable',
                'unreachable',
            )]);
        }
        $issues = [];
        foreach ($rows as $workspace) {
            if ($workspace->status !== LifecycleStatus::Active) {
                $issues[] = new DoctorIssueData(
                    WorkspaceDoctorIssueCode::LifecycleNotActive,
                    DoctorIssueKind::Drift,
                    'workspace',
                    $workspace->id,
                    $workspace->name,
                    'Workspace lifecycle is not active.',
                    'active',
                    $workspace->status->value,
                );
            }
            try {
                $observation = $this->inspector->inspect($workspace);
                foreach ([
                    'checkoutExists' => WorkspaceDoctorIssueCode::CheckoutMissing,
                    'worktreeRegistered' => WorkspaceDoctorIssueCode::WorktreeMissing,
                    'branchMatches' => WorkspaceDoctorIssueCode::BranchMismatch,
                    'documentRootExists' => WorkspaceDoctorIssueCode::DocumentRootMissing,
                    'caddyProjectionMatches' => WorkspaceDoctorIssueCode::CaddyProjectionMismatch,
                    'phpFpmProjectionMatches' => WorkspaceDoctorIssueCode::PhpFpmProjectionMismatch,
                    'certificateProjectionMatches' => WorkspaceDoctorIssueCode::CertificateProjectionMismatch,
                    'dnsProjectionMatches' => WorkspaceDoctorIssueCode::DnsProjectionMismatch,
                ] as $field => $code) {
                    if ($observation->{$field} !== false) {
                        continue;
                    }
                    $issues[] = new DoctorIssueData(
                        $code,
                        DoctorIssueKind::Drift,
                        'workspace',
                        $workspace->id,
                        $workspace->name,
                        'Workspace projection does not match managed intent.',
                        'matching',
                        'mismatch',
                    );
                }
            } catch (DoctorInspectionException) {
                $issues[] = new DoctorIssueData(
                    WorkspaceDoctorIssueCode::InspectionFailed,
                    DoctorIssueKind::Unverifiable,
                    'workspace',
                    $workspace->id,
                    $workspace->name,
                    'Workspace inspection could not be verified.',
                    'verifiable',
                    'unverifiable',
                );
            }
        }

        return DoctorFamilyReportData::fromIssues(DoctorFamily::Workspace, $rows->count(), $issues);
    }
}
