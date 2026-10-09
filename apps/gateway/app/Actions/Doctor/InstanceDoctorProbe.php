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
use App\Domain\Doctor\PrivateRouteProjectionInspector;
use App\Domain\Doctor\PublicRouteEdgeInspector;
use App\Domain\Doctor\RouteApplicationUrlInspector;
use App\Domain\Instances\InstanceProvisionProgress;
use App\Domain\Instances\InstanceSandboxGuard;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Routes\PublicRouteEligibility;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Routes\RouteWebRoot;
use App\Domain\Tasks\TaskWorkspaceLifecycle;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Database\Eloquent\Collection;

final readonly class InstanceDoctorProbe implements DoctorFamilyProbe
{
    public const int StuckRemovalMinutes = 10;

    public const int StuckProvisioningMinutes = 20;

    public function __construct(
        private InstanceStateInspector $inspector,
        private ?PublicRouteEdgeInspector $publicEdge = null,
        private ?PrivateRouteProjectionInspector $privateProjection = null,
        private PublicRouteEligibility $eligibility = new PublicRouteEligibility,
        private ?RouteApplicationUrlInspector $applicationUrls = null,
    ) {}

    public function family(): DoctorFamily
    {
        return DoctorFamily::Instance;
    }

    public function inspect(DoctorNodeContext $context): DoctorFamilyReportData
    {
        $rows = Instance::query()->with(['project', 'tasks'])->whereNull('task_sandbox_id')->where('node_id', $context->node->id)->orderBy('id')->get()->reject(static fn (Instance $instance): bool => InstanceSandboxGuard::isSandbox($instance));
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
            $instanceIssueOffset = count($issues);

            if ($instance->status === InstanceState::Removing) {
                if ($instance->updated_at?->lessThanOrEqualTo(now()->subMinutes(self::StuckRemovalMinutes))) {
                    $issues[] = new DoctorIssueData(
                        InstanceDoctorIssueCode::RemovalStuck,
                        DoctorIssueKind::Drift,
                        'instance',
                        $instance->id,
                        $instance->name,
                        'Instance removal has not completed.',
                        'removed',
                        'removing',
                    );
                }

                continue;
            }

            $settled = TaskWorkspaceLifecycle::settledState($instance);
            if ($this->isProvisioning($instance, $settled)) {
                if ($instance->updated_at?->greaterThanOrEqualTo(now()->subMinutes(self::StuckProvisioningMinutes))) {
                    continue;
                }

                $issues[] = new DoctorIssueData(
                    InstanceDoctorIssueCode::ProvisioningStuck,
                    DoctorIssueKind::Drift,
                    'instance',
                    $instance->id,
                    $instance->name,
                    'Instance provisioning has not completed.',
                    $settled->value,
                    $instance->status->value,
                );

                continue;
            }

            if ($instance->status !== $settled) {
                $issues[] = new DoctorIssueData(
                    InstanceDoctorIssueCode::LifecycleNotActive,
                    DoctorIssueKind::Drift,
                    'instance',
                    $instance->id,
                    $instance->name,
                    $settled === InstanceState::Active
                        ? 'Instance lifecycle is not active.'
                        : 'Task workspace lifecycle is not source resolved.',
                    $settled->value,
                    $instance->status->value,
                );
            }

            if (! InstanceSourceLayout::tryFrom($instance->source_layout) instanceof InstanceSourceLayout) {
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

            if ($instance->placedOnAppProd() && $this->productionAssociationMissing($instance)) {
                $issues[] = $this->projectionIssue($instance, InstanceDoctorIssueCode::PhpFpmAssociationMissing);
            }

            if ($instance->placedOnAppProd() && $this->productionAssociationShared($instance, $rows)) {
                $issues[] = $this->projectionIssue($instance, InstanceDoctorIssueCode::PhpFpmAssociationShared);
            }

            try {
                $observation = $this->inspector->inspect($instance);
                $fields = $instance->placedOnAppProd() ? [
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

            $issues = [
                ...$issues,
                ...$this->privateRouteIssues($instance, $context),
                ...$this->applicationUrlIssues($instance),
                ...$this->publicRouteIssues($instance, $context),
            ];

            if (count($issues) > $instanceIssueOffset) {
                $current = Instance::query()->find($instance->id);

                if (! $current instanceof Instance || $current->status === InstanceState::Removing) {
                    $issues = array_slice($issues, 0, $instanceIssueOffset);

                    if (
                        $current instanceof Instance
                        && $current->updated_at?->lessThanOrEqualTo(now()->subMinutes(self::StuckRemovalMinutes))
                    ) {
                        $issues[] = new DoctorIssueData(
                            InstanceDoctorIssueCode::RemovalStuck,
                            DoctorIssueKind::Drift,
                            'instance',
                            $current->id,
                            $current->name,
                            'Instance removal has not completed.',
                            'removed',
                            'removing',
                        );
                    }
                }
            }
        }

        return DoctorFamilyReportData::fromIssues(DoctorFamily::Instance, $rows->count(), $issues);
    }

    private function isProvisioning(Instance $instance, InstanceState $settled): bool
    {
        return InstanceProvisionProgress::isInFlight($instance, $settled);
    }

    private function productionAssociationMissing(Instance $instance): bool
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

    /** @param Collection<int, Instance> $instances */
    private function productionAssociationShared(Instance $instance, Collection $instances): bool
    {
        if ($instance->selected_php_version === null || $this->productionAssociationMissing($instance)) {
            return false;
        }

        return $instances->contains(function (Instance $other) use ($instance): bool {
            if (
                $other->id === $instance->id
                || ! $other->placedOnAppProd()
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
    private function privateRouteIssues(Instance $instance, DoctorNodeContext $context): array
    {
        $routes = Route::query()
            ->with(['cluster.routerAssignment.node'])
            ->where('publication', RoutePublication::Private)
            ->where('status', RouteStatus::Active)
            ->whereHas('targets', static fn ($query) => $query->where('instance_id', $instance->id))
            ->orderBy('id')
            ->get();

        $issues = [];

        foreach ($routes as $route) {
            $cluster = $route->cluster;
            $router = $cluster !== null ? $this->eligibility->activeRouter($cluster) : null;
            $related = [];
            if ($router instanceof Node && $router->id !== $instance->node_id) {
                $related[$router->id] = $router;
            }
            $scope = $context->scope;
            $inspector = $this->privateProjection;

            foreach ($related as $node) {
                if ($scope === null || ! $scope->has($node->id)) {
                    $issues[] = new DoctorIssueData(
                        InstanceDoctorIssueCode::RelatedNodeUnverifiable,
                        DoctorIssueKind::Unverifiable,
                        'instance',
                        $instance->id,
                        $instance->name,
                        'Private Route projection cannot be verified from the selected nodes.',
                        'verifiable',
                        'unverifiable',
                    );

                    continue 2;
                }
            }

            if ($inspector === null) {
                continue;
            }

            try {
                $observation = $inspector->inspect($instance, $route);
            } catch (DoctorInspectionException) {
                $issues[] = $this->inspectionFailedIssue($instance);

                continue;
            }

            $fields = [
                'routingScopeMatches' => InstanceDoctorIssueCode::PrivateRoutingScopeMismatch,
                'routerCaddyMatches' => InstanceDoctorIssueCode::RouterCaddyMismatch,
                'workloadCaddyMatches' => InstanceDoctorIssueCode::WorkloadCaddyMismatch,
                'certificateMatches' => InstanceDoctorIssueCode::PrivateCertificateMismatch,
                'dnsMatches' => InstanceDoctorIssueCode::PrivateDnsMismatch,
                'firewallMatches' => InstanceDoctorIssueCode::PrivateFirewallMismatch,
                'laravelUrlMatches' => InstanceDoctorIssueCode::LaravelUrlMismatch,
                'targetSetMatches' => InstanceDoctorIssueCode::TargetSetMismatch,
                'associationMatches' => InstanceDoctorIssueCode::RouteAssociationMismatch,
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
        }

        return $issues;
    }

    /**
     * Checks `APP_URL` in each application directory that a Route with a web root serves, against the
     * Route that wins the directory. It covers the directories the writer covers, so an Instance whose
     * Routes have no web root gets no check here.
     *
     * @return list<DoctorIssueData>
     */
    private function applicationUrlIssues(Instance $instance): array
    {
        if (
            ! $this->applicationUrls instanceof RouteApplicationUrlInspector
            || $instance->placedOnAppProd()
            || $instance->selected_php_version === null
        ) {
            return [];
        }

        $winners = RouteWebRoot::applicationUrlRoutes($instance, RouteWebRoot::applicationUrlCandidates($instance));
        if ($winners === []) {
            return [];
        }

        $expected = [];
        foreach ($winners as $directory => $route) {
            $expected[(string) $directory] = "https://{$route->domain}";
        }

        try {
            $observed = $this->applicationUrls->inspect($instance, $expected);
        } catch (DoctorInspectionException) {
            return [$this->inspectionFailedIssue($instance)];
        }

        $issues = [];
        $inspectionFailed = false;
        foreach (array_keys($expected) as $directory) {
            $matches = $observed[$directory] ?? null;

            if ($matches === null) {
                $inspectionFailed = true;

                continue;
            }

            if (! $matches) {
                $name = $directory === '' ? '.' : $directory;
                $issues[] = new DoctorIssueData(
                    InstanceDoctorIssueCode::LaravelUrlMismatch,
                    DoctorIssueKind::Drift,
                    'instance',
                    $instance->id,
                    $instance->name,
                    "The Laravel URL in application directory [{$name}] does not match the Route that serves it.",
                    'matching',
                    'mismatch',
                );
            }
        }

        if ($inspectionFailed) {
            $issues[] = $this->inspectionFailedIssue($instance);
        }

        return $issues;
    }

    /** @return list<DoctorIssueData> */
    private function publicRouteIssues(Instance $instance, DoctorNodeContext $context): array
    {
        $routes = Route::query()
            ->with(['cluster.routerAssignment.node', 'cluster.ingressAssignment.node'])
            ->where('publication', RoutePublication::Public)
            ->whereHas('targets', static fn ($query) => $query->where('instance_id', $instance->id))
            ->orderBy('id')
            ->get();

        $issues = [];

        foreach ($routes as $route) {
            if (! $this->eligibility->publicEdgeIsLive($route)) {
                continue;
            }

            $cluster = $route->cluster;
            $ingress = $cluster !== null ? $this->eligibility->servingIngress($cluster) : null;
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

    private function projectionIssue(Instance $instance, InstanceDoctorIssueCode $code): DoctorIssueData
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

    private function inspectionFailedIssue(Instance $instance): DoctorIssueData
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
