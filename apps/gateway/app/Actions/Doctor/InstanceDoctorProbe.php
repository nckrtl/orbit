<?php

declare(strict_types=1);

namespace App\Actions\Doctor;

use App\Data\Doctor\DoctorFamilyReportData;
use App\Data\Doctor\DoctorIssueData;
use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorFamilyProbe;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\DoctorIssueKind;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\InstanceDoctorIssueCode;
use App\Domain\Doctor\InstanceStateInspector;
use App\Models\AppInstance;
use Illuminate\Database\Eloquent\Collection;

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

            if (! AppInstanceSourceLayout::tryFrom($instance->source_layout) instanceof AppInstanceSourceLayout) {
                $issues[] = new DoctorIssueData(
                    InstanceDoctorIssueCode::SourceLayoutMismatch,
                    DoctorIssueKind::Drift,
                    'instance',
                    $instance->id,
                    $instance->name,
                    'Instance source ownership does not match managed intent.',
                    'checkout or worktree',
                    $instance->source_layout,
                );

                continue;
            }

            if ($instance->migration_required) {
                $issues[] = new DoctorIssueData(
                    InstanceDoctorIssueCode::MigrationRequired,
                    DoctorIssueKind::Drift,
                    'instance',
                    $instance->id,
                    $instance->name,
                    'Instance source requires manual migration.',
                    'migration complete',
                    'migration required',
                );
            }

            if ($instance->environment === 'production' && $this->productionAssociationMissing($instance)) {
                $issues[] = $this->projectionIssue($instance, InstanceDoctorIssueCode::PhpFpmAssociationMissing);
            }

            if ($instance->environment === 'production' && $this->productionAssociationShared($instance, $rows)) {
                $issues[] = $this->projectionIssue($instance, InstanceDoctorIssueCode::PhpFpmAssociationShared);
            }

            try {
                $observation = $this->inspector->inspect($instance);
                $fields = $instance->environment === 'production' ? [
                    'productionHomeMatches' => InstanceDoctorIssueCode::ProductionHomeMismatch,
                    'releaseSelectionMatches' => InstanceDoctorIssueCode::ReleaseSelectionMismatch,
                    'selectedReleaseRootMatches' => InstanceDoctorIssueCode::SelectedReleaseRootMismatch,
                    'environmentProjectionMatches' => InstanceDoctorIssueCode::EnvironmentProjectionMismatch,
                    'phpFpmProjectionMatches' => InstanceDoctorIssueCode::PhpFpmProjectionMismatch,
                    'caddyProjectionMatches' => InstanceDoctorIssueCode::CaddyProjectionMismatch,
                ] : [
                    'checkoutExists' => InstanceDoctorIssueCode::CheckoutMissing,
                    'repositoryLayoutMatches' => InstanceDoctorIssueCode::RepositoryLayoutMismatch,
                    'originMatches' => InstanceDoctorIssueCode::OriginMismatch,
                    'sourceIdentityMatches' => InstanceDoctorIssueCode::SourceIdentityMismatch,
                ];

                foreach ($fields as $field => $code) {
                    if ($observation->{$field} !== false) {
                        continue;
                    }
                    $issues[] = $this->projectionIssue($instance, $code);
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

    private function productionAssociationMissing(AppInstance $instance): bool
    {
        if ($instance->selected_php_version === null) {
            return false;
        }

        return array_any(
            [
                $instance->production_php_service,
                $instance->production_php_pool,
                $instance->production_php_socket,
            ],
            static fn (?string $value): bool => ! is_string($value) || $value === '',
        );
    }

    /** @param Collection<int, AppInstance> $instances */
    private function productionAssociationShared(AppInstance $instance, Collection $instances): bool
    {
        if ($instance->selected_php_version === null || $this->productionAssociationMissing($instance)) {
            return false;
        }

        return $instances->contains(function (AppInstance $other) use ($instance): bool {
            if (
                $other->id === $instance->id
                || $other->environment !== 'production'
                || $other->selected_php_version === null
            ) {
                return false;
            }

            return
                $other->production_php_service === $instance->production_php_service
                || $other->production_php_pool === $instance->production_php_pool
                || $other->production_php_socket === $instance->production_php_socket;
        });
    }

    private function projectionIssue(AppInstance $instance, InstanceDoctorIssueCode $code): DoctorIssueData
    {
        return new DoctorIssueData(
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
}
