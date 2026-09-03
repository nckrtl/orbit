<?php

declare(strict_types=1);

namespace App\Actions\Doctor;

use App\Data\Doctor\DoctorFamilyReportData;
use App\Data\Doctor\DoctorIssueData;
use App\Domain\AppInstances\AppInstanceSourceKind;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\RegisteredWorktreeInspector;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorFamilyProbe;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\DoctorIssueKind;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\InstanceDoctorIssueCode;
use App\Domain\Doctor\InstanceStateInspector;
use App\Models\AppInstance;

final readonly class InstanceDoctorProbe implements DoctorFamilyProbe
{
    public function __construct(
        private InstanceStateInspector $inspector,
        private ?RegisteredWorktreeInspector $registeredWorktrees = null,
    ) {}

    public function family(): DoctorFamily
    {
        return DoctorFamily::Instance;
    }

    public function inspect(DoctorNodeContext $context): DoctorFamilyReportData
    {
        $rows = AppInstance::query()->where('node_id', $context->node->id)->orderBy('id')->get();
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
            if ($instance->status !== AppInstanceState::Active) {
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

            if ($instance->source_kind === AppInstanceSourceKind::RegisteredWorktree->value) {
                $this->inspectRegisteredWorktree($instance, $issues);

                continue;
            }

            if ($instance->source_kind !== AppInstanceSourceKind::ManagedClone->value) {
                $issues[] = new DoctorIssueData(
                    InstanceDoctorIssueCode::SourceKindMismatch,
                    DoctorIssueKind::Drift,
                    'instance',
                    $instance->id,
                    $instance->name,
                    'Instance source ownership is not recognized.',
                    'managed_clone or registered_worktree',
                    $instance->source_kind,
                );

                continue;
            }

            try {
                $observation = $this->inspector->inspect($instance);
                foreach ([
                    'checkoutExists' => InstanceDoctorIssueCode::CheckoutMissing,
                    'repositoryIndependent' => InstanceDoctorIssueCode::RepositoryNotIndependent,
                    'originMatches' => InstanceDoctorIssueCode::OriginMismatch,
                    'sourceIdentityMatches' => InstanceDoctorIssueCode::SourceIdentityMismatch,
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

    /** @param list<DoctorIssueData> $issues */
    private function inspectRegisteredWorktree(AppInstance $instance, array &$issues): void
    {
        $instance->loadMissing(['app', 'node']);
        $effectiveRoot = $instance->effectiveRoot();

        try {
            if (! is_string($effectiveRoot)) {
                throw new DoctorInspectionException;
            }

            if (! $this->registeredWorktrees instanceof RegisteredWorktreeInspector) {
                throw new DoctorInspectionException;
            }

            $observation = $this->registeredWorktrees->inspect(
                $instance->node,
                $instance->app,
                $instance->checkout_path,
                $effectiveRoot,
            );
        } catch (\Throwable) {
            $issues[] = new DoctorIssueData(
                InstanceDoctorIssueCode::RegisteredWorktreeUnavailable,
                DoctorIssueKind::Drift,
                'instance',
                $instance->id,
                $instance->name,
                'Registered worktree source is unavailable or no longer verifiable.',
                'verifiable',
                'unavailable',
            );

            return;
        }

        if ($instance->source_identity !== $observation->sourceIdentity) {
            $issues[] = new DoctorIssueData(
                InstanceDoctorIssueCode::SourceIdentityMismatch,
                DoctorIssueKind::Drift,
                'instance',
                $instance->id,
                $instance->name,
                'Registered worktree identity does not match its immutable registration.',
                'matching',
                'mismatch',
            );
        }
    }
}
