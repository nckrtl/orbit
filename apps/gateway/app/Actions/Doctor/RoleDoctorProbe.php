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
use App\Domain\Doctor\GatewayVpnInspectionData;
use App\Domain\Doctor\GatewayVpnStateInspector;
use App\Domain\Doctor\RoleDoctorIssueCode;
use App\Domain\Doctor\RoleInspectionData;
use App\Domain\Doctor\RoleStateInspector;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\RoleRegistry;
use App\Domain\Shared\LifecycleStatus;
use App\Models\NodeRole;
use Illuminate\Database\Eloquent\Collection;

/**
 * @mago-expect lint:cyclomatic-complexity The probe keeps every stable role issue branch explicit.
 * @mago-expect lint:kan-defect The score reflects the closed lifecycle, conflict, and projection matrix.
 */
final readonly class RoleDoctorProbe implements DoctorFamilyProbe
{
    public function __construct(
        private RoleStateInspector $inspector,
        private GatewayVpnStateInspector $vpnInspector,
        private RoleRegistry $registry = new RoleRegistry,
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
            $this->add($issues, $role, $this->issue(
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

        return DoctorFamilyReportData::fromIssues(
            DoctorFamily::Role,
            $roles->count(),
            $this->ordered($issues),
        );
    }

    /**
     * @param array<int, list<DoctorIssueData>> $issues
     * @param Collection<int, NodeRole> $roles
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
     * @param array<int, list<DoctorIssueData>> $issues
     * @param Collection<int, NodeRole> $roles
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
                RoleDoctorIssueCode::VpnOrderingMissing,
                $state->dnsOrderingInstalled,
                'Private DNS is not ordered after the managed VPN interface.',
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
     * @param array<int, list<DoctorIssueData>> $issues
     * @return list<DoctorIssueData>
     */
    private function ordered(array $issues): array
    {
        ksort($issues, SORT_NUMERIC);

        return array_merge(...array_values($issues));
    }

    /** @mago-expect lint:excessive-parameter-list A bounded issue needs its stable identity and comparison. */
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
