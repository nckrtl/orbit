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
use App\Domain\Doctor\PublicRouteEdgeInspector;
use App\Domain\Routes\PublicRouteEligibility;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RoutePublicPublication;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Database\Eloquent\Collection;

final readonly class InstanceDoctorProbe implements DoctorFamilyProbe
{
    public function __construct(
        private InstanceStateInspector $inspector,
        private ?PublicRouteEdgeInspector $publicEdge = null,
        private PublicRouteEligibility $eligibility = new PublicRouteEligibility,
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

                $inspectionFailed = false;
                foreach ($fields as $field => $code) {
                    if ($observation->{$field} === null) {
                        $inspectionFailed = true;

                        continue;
                    }

                    if ($observation->{$field} !== false) {
                        continue;
                    }
                    $issues[] = $this->projectionIssue($instance, $code);
                }

                if ($inspectionFailed) {
                    $issues[] = $this->inspectionFailedIssue($instance);
                }
            } catch (DoctorInspectionException) {
                $issues[] = $this->inspectionFailedIssue($instance);
            }

            $issues = [...$issues, ...$this->publicRouteIssues($instance, $context)];
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

    /** @return list<DoctorIssueData> */
    private function publicRouteIssues(AppInstance $instance, DoctorNodeContext $context): array
    {
        $routes = Route::query()
            ->with(['cluster.routerAssignment.node', 'cluster.ingressAssignment.node'])
            ->where('publication', RoutePublication::Public)
            ->where('public_publication', RoutePublicPublication::Active)
            ->whereHas('targets', static fn ($query) => $query->where('app_instance_id', $instance->id))
            ->orderBy('id')
            ->get();

        $issues = [];

        foreach ($routes as $route) {
            $cluster = $route->cluster;
            $ingress = $cluster !== null ? $this->eligibility->activeIngress($cluster) : null;
            $router = $cluster !== null ? $this->eligibility->activeRouter($cluster) : null;
            $related = [];
            foreach ([$ingress, $router] as $node) {
                if ($node !== null) {
                    $related[$node->id] = $node;
                }
            }
            $scope = $context->scope;
            $inspector = $this->publicEdge;

            foreach ($related as $node) {
                if ($scope === null || ! $scope->has($node->id)) {
                    $issues[] = new DoctorIssueData(
                        InstanceDoctorIssueCode::RelatedNodeUnverifiable,
                        DoctorIssueKind::Unverifiable,
                        'instance',
                        $instance->id,
                        $instance->name,
                        'Public Route projection cannot be verified from the selected nodes.',
                        'verifiable',
                        'unverifiable',
                    );

                    continue 2;
                }
            }

            if ($inspector === null || ! $ingress instanceof Node) {
                continue;
            }

            try {
                $observation = $inspector->inspect($ingress, $route);
            } catch (DoctorInspectionException) {
                $issues[] = $this->inspectionFailedIssue($instance);

                continue;
            }

            $fields = [
                'ingressProjectionMatches' => InstanceDoctorIssueCode::PublicIngressMismatch,
                'privateForwardingMatches' => InstanceDoctorIssueCode::PrivateForwardingMismatch,
                'publicTlsMatches' => InstanceDoctorIssueCode::PublicTlsMismatch,
                'firewallMatches' => InstanceDoctorIssueCode::PublicFirewallMismatch,
            ];

            foreach ($fields as $field => $code) {
                if ($observation->{$field} === false) {
                    $issues[] = $this->projectionIssue($instance, $code);
                }
            }
        }

        return $issues;
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

    private function inspectionFailedIssue(AppInstance $instance): DoctorIssueData
    {
        return new DoctorIssueData(
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
