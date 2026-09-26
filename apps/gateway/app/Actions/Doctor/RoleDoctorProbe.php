<?php

declare(strict_types=1);

namespace App\Actions\Doctor;

use App\Data\Doctor\DoctorFamilyReportData;
use App\Data\Doctor\DoctorIssueData;
use App\Domain\Doctor\CaddyBuildInspector;
use App\Domain\Doctor\CaddyBuildObservation;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorFamilyProbe;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\DoctorIssueKind;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\GatewayVpnInspectionData;
use App\Domain\Doctor\GatewayVpnStateInspector;
use App\Domain\Doctor\RoleDoctorIssueCode;
use App\Domain\Doctor\RoleInspectionData;
use App\Domain\Doctor\RoleStateInspector;
use App\Domain\Nodes\CaddyRelease;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\RoleRegistry;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Nodes\Roles\NodeRoleConvergeLock;
use App\Models\NodeRole;
use Illuminate\Database\Eloquent\Collection;

final readonly class RoleDoctorProbe implements DoctorFamilyProbe
{
    /** Roles that publish Caddy sites; Caddy build drift is reported on the first active one. */
    private const array CaddySiteRoles = [
        RoleName::Gateway,
        RoleName::Router,
        RoleName::Ingress,
        RoleName::AppDev,
        RoleName::AppProd,
        RoleName::WebSocket,
        RoleName::Analytics,
    ];

    public function __construct(
        private RoleStateInspector $inspector,
        private GatewayVpnStateInspector $vpnInspector,
        private RoleRegistry $registry = new RoleRegistry,
        private ?CaddyBuildInspector $caddyBuilds = null,
        private ?NodeRoleConvergeLock $roleLock = null,
    ) {}

    public function family(): DoctorFamily
    {
        return DoctorFamily::Role;
    }

    public function inspect(DoctorNodeContext $context): DoctorFamilyReportData
    {
        $roles = NodeRole::query()->where('node_id', $context->node->id)->orderBy('id')->get();
        if ($roles->isEmpty()) {
            return DoctorFamilyReportData::fromIssues(DoctorFamily::Role, 0, []);
        }

        $issues = [];
        foreach ($roles as $role) {
            if ($role->status === LifecycleStatus::Active) {
                continue;
            }
            $this->add($issues, $role, $role->isStaleClaim() && ! ($this->roleLock ?? app(NodeRoleConvergeLock::class))->isHeld($context->node) ? $this->issue(
                $role,
                RoleDoctorIssueCode::ClaimStale,
                DoctorIssueKind::Drift,
                'Role operation stopped without finishing.',
                'active',
                $role->status->value,
            ) : $this->issue(
                $role,
                RoleDoctorIssueCode::LifecycleNotActive,
                DoctorIssueKind::Drift,
                'Role lifecycle is not active.',
                'active',
                $role->status->value,
            ));
        }
        $this->addAssignmentConflicts($issues, $roles);
        $this->addSingletonConflicts($issues, $roles);
        $this->addIngressClusterIssues($issues, $roles);

        $needsLiveInspection = $roles->contains(
            static fn (NodeRole $role): bool => $role->status === LifecycleStatus::Active,
        );
        if ($needsLiveInspection && ($context->inspectionFailed || ! $context->inspection->reachable)) {
            $ordered = $this->ordered($issues);
            $ordered[] = new DoctorIssueData(
                RoleDoctorIssueCode::NodeUnreachable,
                DoctorIssueKind::Unverifiable,
                'role',
                null,
                null,
                'Role state cannot be inspected because the node is unreachable.',
                'reachable',
                'unreachable',
            );

            return DoctorFamilyReportData::fromIssues(DoctorFamily::Role, $roles->count(), $ordered);
        }

        foreach ($roles as $role) {
            if ($role->status !== LifecycleStatus::Active) {
                continue;
            }

            try {
                $state = $this->inspector->inspect($role);
                $this->addRoleStateIssues($issues, $role, $state);
                if ($role->role === RoleName::Vpn) {
                    $this->addVpnStateIssues($issues, $role, $this->vpnInspector->inspect($role));
                }
            } catch (DoctorInspectionException) {
                $this->add($issues, $role, $this->issue(
                    $role,
                    RoleDoctorIssueCode::InspectionFailed,
                    DoctorIssueKind::Unverifiable,
                    'Role observation failed.',
                    'verifiable',
                    'unverifiable',
                ));
            }
        }

        if (! $context->inspectionFailed && $context->inspection->reachable) {
            $this->addCaddyBuildIssue($issues, $context, $roles);
        }

        return DoctorFamilyReportData::fromIssues(
            DoctorFamily::Role,
            $roles->count(),
            $this->ordered($issues),
        );
    }

    /**
     * One Node Caddy build serves every role on the Node, so its drift is reported once, on the first
     * active role that publishes Caddy sites (ADR 0141).
     *
     * @param  array<int, list<DoctorIssueData>>  $issues
     * @param  Collection<int, NodeRole>  $roles
     */
    private function addCaddyBuildIssue(array &$issues, DoctorNodeContext $context, Collection $roles): void
    {
        $active = $roles->filter(static fn (NodeRole $role): bool => $role->status === LifecycleStatus::Active);
        $role = $active->first(static fn (NodeRole $role): bool => in_array($role->role, self::CaddySiteRoles, strict: true))
            ?? $active->first();

        if (! $role instanceof NodeRole) {
            return;
        }

        try {
            $observation = ($this->caddyBuilds ?? app(CaddyBuildInspector::class))->inspect($context->node);
        } catch (DoctorInspectionException) {
            $this->add($issues, $role, $this->issue(
                $role,
                RoleDoctorIssueCode::InspectionFailed,
                DoctorIssueKind::Unverifiable,
                'Caddy build observation failed.',
                'verifiable',
                'unverifiable',
            ));

            return;
        }

        if (! $observation instanceof CaddyBuildObservation || $observation->matches) {
            return;
        }

        if ($observation->building) {
            $this->add($issues, $role, $this->issue(
                $role,
                RoleDoctorIssueCode::InspectionFailed,
                DoctorIssueKind::Unverifiable,
                'A Caddy build for this Node was running, so Doctor did not compare its Caddyfile.',
                'verifiable',
                'building',
            ));

            return;
        }

        $sources = $observation->sources === [] ? 'none' : implode(', ', $observation->sources);
        $this->add($issues, $role, $this->issue(
            $role,
            RoleDoctorIssueCode::CaddyBuildDrift,
            DoctorIssueKind::Drift,
            $observation->expectedVersion === null
                ? "Stored state does not render a buildable Caddyfile for this Node. Site sources: {$sources}."
                : "The live Caddyfile differs from a fresh Node Caddy build. Site sources: {$sources}.",
            $observation->expectedVersion ?? 'buildable',
            $observation->expectedVersion === null ? 'refused' : ($observation->liveVersion ?? 'not_built'),
        ));
    }

    /**
     * @param  array<int, list<DoctorIssueData>>  $issues
     * @param  Collection<int, NodeRole>  $roles
     */
    private function addAssignmentConflicts(array &$issues, Collection $roles): void
    {
        $conflicting = [];
        foreach ($roles as $offset => $role) {
            foreach ($roles->slice($offset + 1) as $other) {
                if (! $this->registry->conflicts($role->role, $other->role)) {
                    continue;
                }
                $conflicting[$role->id] = $role;
                $conflicting[$other->id] = $other;
            }
        }

        foreach ($roles as $role) {
            if (! array_key_exists($role->id, $conflicting)) {
                continue;
            }
            $this->add($issues, $role, $this->issue(
                $role,
                RoleDoctorIssueCode::AssignmentConflict,
                DoctorIssueKind::Drift,
                'Role assignment conflicts with another role.',
                'compatible',
                'conflict',
            ));
        }
    }

    /**
     * @param  array<int, list<DoctorIssueData>>  $issues
     * @param  Collection<int, NodeRole>  $roles
     */
    private function addIngressClusterIssues(array &$issues, Collection $roles): void
    {
        $ingressRoles = $roles->where('role', RoleName::Ingress);

        foreach ($ingressRoles as $role) {
            $role->loadMissing('node');

            if (is_int($role->cluster_id) && $role->cluster_id === $role->node->cluster_id) {
                continue;
            }

            $this->add($issues, $role, $this->issue(
                $role,
                RoleDoctorIssueCode::ClusterOwnershipMismatch,
                DoctorIssueKind::Drift,
                'Ingress Cluster ownership does not match its Node.',
                'node_cluster',
                'mismatch',
            ));
        }

        $clusterIds = $ingressRoles
            ->where('status', LifecycleStatus::Active)
            ->pluck('cluster_id')
            ->filter(static fn (mixed $clusterId): bool => is_int($clusterId))
            ->unique()
            ->values();

        if ($clusterIds->isEmpty()) {
            return;
        }

        $fleetRoles = NodeRole::query()
            ->where('role', RoleName::Ingress)
            ->where('status', LifecycleStatus::Active)
            ->whereIn('cluster_id', $clusterIds)
            ->orderBy('id')
            ->get()
            ->groupBy('cluster_id');

        foreach ($fleetRoles as $clusterRoles) {
            if ($clusterRoles->count() < 2) {
                continue;
            }

            foreach ($clusterRoles as $role) {
                $this->add($issues, $role, $this->issue(
                    $role,
                    RoleDoctorIssueCode::ClusterCardinalityConflict,
                    DoctorIssueKind::Drift,
                    'Cluster has more than one active Ingress role.',
                    'unique',
                    'conflict',
                ));
            }
        }
    }

    /**
     * @param  array<int, list<DoctorIssueData>>  $issues
     * @param  Collection<int, NodeRole>  $roles
     */
    private function addSingletonConflicts(array &$issues, Collection $roles): void
    {
        $singletons = $roles
            ->filter(fn (NodeRole $role): bool => $this->registry->definition($role->role)->singleton)
            ->map(static fn (NodeRole $role): string => $role->role->value)
            ->unique()
            ->values();

        foreach ($singletons as $roleName) {
            $fleet = NodeRole::query()->where('role', $roleName)->orderBy('id')->get();
            if ($fleet->count() < 2) {
                continue;
            }
            foreach ($fleet as $role) {
                $this->add($issues, $role, $this->issue(
                    $role,
                    RoleDoctorIssueCode::SingletonConflict,
                    DoctorIssueKind::Drift,
                    'Singleton role is assigned more than once.',
                    'unique',
                    'conflict',
                ));
            }
        }
    }

    /** @param array<int, list<DoctorIssueData>> $issues */
    private function addRoleStateIssues(array &$issues, NodeRole $role, RoleInspectionData $state): void
    {
        if (! $state->packagesPresent) {
            $this->add($issues, $role, $this->issue(
                $role,
                RoleDoctorIssueCode::PackagesMissing,
                DoctorIssueKind::Drift,
                'Required role packages are missing.',
                true,
                false,
            ));
        }
        if (is_string($state->caddyVersion) && ! CaddyRelease::supports($state->caddyVersion)) {
            $this->add($issues, $role, $this->issue(
                $role,
                RoleDoctorIssueCode::CaddyVersionUnsupported,
                DoctorIssueKind::Drift,
                'Installed Caddy is older than the release Orbit renders against.',
                CaddyRelease::constraint(),
                CaddyRelease::reported($state->caddyVersion) ?? 'unknown',
            ));
        }
        if (! $state->servicesActive) {
            $this->add($issues, $role, $this->issue(
                $role,
                RoleDoctorIssueCode::ServicesInactive,
                DoctorIssueKind::Drift,
                'Required role services are inactive.',
                true,
                false,
            ));
        }
        if ($state->privateDnsRouteMatches === false) {
            $this->add($issues, $role, $this->issue(
                $role,
                RoleDoctorIssueCode::PrivateDnsRouteMismatch,
                DoctorIssueKind::Drift,
                'The Gateway machine does not route the private domain to VPN DNS.',
                true,
                false,
            ));
        }
        if (! $state->firewallProjectionMatches) {
            $this->add($issues, $role, $this->issue(
                $role,
                RoleDoctorIssueCode::FirewallProjectionMismatch,
                DoctorIssueKind::Drift,
                'Role firewall projection does not match managed intent.',
                true,
                false,
            ));
        }
    }

    /** @param array<int, list<DoctorIssueData>> $issues */
    private function addVpnStateIssues(array &$issues, NodeRole $role, GatewayVpnInspectionData $state): void
    {
        foreach ([
            [RoleDoctorIssueCode::VpnInactive, $state->interfaceActive, 'The managed VPN interface is inactive.'],
            [
                RoleDoctorIssueCode::VpnProjectionMismatch,
                $state->serverConfigMatches,
                'VPN server projection does not match managed intent.',
            ],
            [
                RoleDoctorIssueCode::DnsProjectionMismatch,
                $state->dnsConfigMatches,
                'Private DNS projection does not match managed intent.',
            ],
            [
                RoleDoctorIssueCode::DnsSnippetConflict,
                $state->dnsConflictAbsent,
                'A retired stock DNS snippet is still present.',
            ],
        ] as [$code, $matches, $summary]) {
            if ($matches) {
                continue;
            }
            $this->add($issues, $role, $this->issue(
                $role,
                $code,
                DoctorIssueKind::Drift,
                $summary,
                true,
                false,
            ));
        }
    }

    /** @param array<int, list<DoctorIssueData>> $issues */
    private function add(array &$issues, NodeRole $role, DoctorIssueData $issue): void
    {
        $issues[$role->id] ??= [];
        $issues[$role->id][] = $issue;
    }

    /**
     * @param  array<int, list<DoctorIssueData>>  $issues
     * @return list<DoctorIssueData>
     */
    private function ordered(array $issues): array
    {
        ksort($issues, SORT_NUMERIC);

        return array_merge(...array_values($issues));
    }

    private function issue(
        NodeRole $role,
        RoleDoctorIssueCode $code,
        DoctorIssueKind $kind,
        string $summary,
        bool|string $expected,
        bool|string $observed,
    ): DoctorIssueData {
        return new DoctorIssueData(
            $code,
            $kind,
            'role',
            $role->id,
            $role->role->value,
            $summary,
            $expected,
            $observed,
        );
    }
}
