<?php

declare(strict_types=1);

use App\Actions\Doctor\RoleDoctorProbe;
use App\Data\Doctor\DoctorIssueData;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\GatewayVpnInspectionData;
use App\Domain\Doctor\GatewayVpnStateInspector;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Doctor\RoleInspectionData;
use App\Domain\Doctor\RoleStateInspector;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\NodeRole;

it('returns a healthy empty report without live inspection when the node has no roles', function (): void {
    $roleCalls = 0;
    $vpnCalls = 0;
    $report = new RoleDoctorProbe(
        role_probe_state_inspector($roleCalls),
        role_probe_vpn_inspector($vpnCalls),
    )->inspect(role_probe_context(role_probe_node('empty')));

    expect($report->checked)
        ->toBe(0)
        ->and($report->issues)
        ->toBeEmpty()
        ->and($roleCalls)
        ->toBe(0)
        ->and($vpnCalls)
        ->toBe(0);
});

it('inspects active roles in ID order and checks the VPN projection once', function (): void {
    $node = role_probe_node('healthy');
    $first = role_probe_assignment($node, RoleName::Vpn);
    $second = role_probe_assignment($node, RoleName::AppDev);
    $seen = [];
    $vpnCalls = 0;
    $report = new RoleDoctorProbe(
        new class($seen) implements RoleStateInspector {
            public function __construct(
                private array &$seen,
            ) {}

            public function inspect(NodeRole $role): RoleInspectionData
            {
                $this->seen[] = $role->id;

                return new RoleInspectionData(true, true, true);
            }
        },
        role_probe_vpn_inspector($vpnCalls),
    )->inspect(role_probe_context($node));

    expect($report->checked)
        ->toBe(2)
        ->and($report->issues)
        ->toBeEmpty()
        ->and($seen)
        ->toBe([$first->id, $second->id])
        ->and($vpnCalls)
        ->toBe(1);
});

it('reports lifecycle and each conflicting assignment once without leaking stored diagnostics', function (): void {
    $node = role_probe_node('conflicts');
    $gateway = role_probe_assignment($node, RoleName::Gateway);
    $appDev = role_probe_assignment(
        $node,
        RoleName::AppDev,
        LifecycleStatus::Failed,
        failedStep: 'secret-step',
        errorCode: 'secret-code',
    );
    $appProd = role_probe_assignment($node, RoleName::AppProd);
    $roleCalls = 0;
    $vpnCalls = 0;
    $report = new RoleDoctorProbe(
        role_probe_state_inspector($roleCalls),
        role_probe_vpn_inspector($vpnCalls),
    )->inspect(role_probe_context($node));

    expect($report->checked)
        ->toBe(3)
        ->and(array_map(
            static fn (DoctorIssueData $issue): array => [$issue->resourceId, $issue->code],
            $report->issues,
        ))
        ->toBe([
            [$gateway->id, 'role.assignment_conflict'],
            [$appDev->id, 'role.lifecycle_not_active'],
            [$appDev->id, 'role.assignment_conflict'],
            [$appProd->id, 'role.assignment_conflict'],
        ])
        ->and($roleCalls)
        ->toBe(2)
        ->and(json_encode($report))
        ->not->toContain('secret-step', 'secret-code', 'failed_step', 'error_code');
});

it('marks every conflicting singleton row across the fleet', function (): void {
    $selected = role_probe_node('selected-singleton');
    $other = role_probe_node('other-singleton');
    $selectedRole = role_probe_assignment($selected, RoleName::Gateway);
    $otherRole = role_probe_assignment($other, RoleName::Gateway);
    $roleCalls = 0;
    $vpnCalls = 0;
    $report = new RoleDoctorProbe(
        role_probe_state_inspector($roleCalls),
        role_probe_vpn_inspector($vpnCalls),
    )->inspect(role_probe_context($selected));

    expect($report->checked)
        ->toBe(1)
        ->and(array_map(
            static fn (DoctorIssueData $issue): array => [$issue->resourceId, $issue->code],
            $report->issues,
        ))
        ->toBe([
            [$selectedRole->id, 'role.singleton_conflict'],
            [$otherRole->id, 'role.singleton_conflict'],
        ])
        ->and($roleCalls)
        ->toBe(1);
});

it('reports the complete role and VPN drift matrix in stable field order', function (): void {
    $node = role_probe_node('drift');
    $role = role_probe_assignment($node, RoleName::Vpn);
    $roleCalls = 0;
    $vpnCalls = 0;
    $report = new RoleDoctorProbe(
        role_probe_state_inspector($roleCalls, new RoleInspectionData(false, false, false)),
        role_probe_vpn_inspector($vpnCalls, new GatewayVpnInspectionData(false, false, false, false)),
    )->inspect(role_probe_context($node));

    expect($report->checked)
        ->toBe(1)
        ->and(array_map(static fn (DoctorIssueData $issue): string => $issue->code, $report->issues))
        ->toBe([
            'role.packages_missing',
            'role.services_inactive',
            'role.firewall_projection_mismatch',
            'role.vpn_inactive',
            'role.vpn_projection_mismatch',
            'role.dns_projection_mismatch',
            'role.vpn_ordering_missing',
        ])
        ->and(array_unique(array_map(
            static fn (DoctorIssueData $issue): int|string|null => $issue->resourceId,
            $report->issues,
        )))
        ->toBe([$role->id])
        ->and(json_encode($report))
        ->not->toContain('package-output', 'service-output', 'private-key', 'vpn-setting');
});

it('retains SQLite findings and emits one bounded issue when the node is unreachable', function (): void {
    $node = role_probe_node('unreachable');
    $gateway = role_probe_assignment($node, RoleName::Gateway);
    $appDev = role_probe_assignment($node, RoleName::AppDev, LifecycleStatus::Failed);
    $roleCalls = 0;
    $vpnCalls = 0;
    $report = new RoleDoctorProbe(
        role_probe_state_inspector($roleCalls),
        role_probe_vpn_inspector($vpnCalls),
    )->inspect(role_probe_context($node, reachable: false));

    expect($report->checked)
        ->toBe(2)
        ->and(array_map(static fn (DoctorIssueData $issue): string => $issue->code, $report->issues))
        ->toBe([
            'role.assignment_conflict',
            'role.lifecycle_not_active',
            'role.assignment_conflict',
            'role.node_unreachable',
        ])
        ->and($report->issues[0]->resourceId)
        ->toBe($gateway->id)
        ->and($report->issues[1]->resourceId)
        ->toBe($appDev->id)
        ->and($report->issues[3]->resourceId)
        ->toBeNull()
        ->and($roleCalls)
        ->toBe(0)
        ->and($vpnCalls)
        ->toBe(0);
});

it('continues after a typed per-row inspection failure and does not expose exception text', function (): void {
    $node = role_probe_node('typed-failure');
    $failed = role_probe_assignment($node, RoleName::Vpn);
    $healthy = role_probe_assignment($node, RoleName::AppDev);
    $seen = [];
    $vpnCalls = 0;
    $report = new RoleDoctorProbe(
        new class($failed, $seen) implements RoleStateInspector {
            public function __construct(
                private NodeRole $failed,
                private array &$seen,
            ) {}

            public function inspect(NodeRole $role): RoleInspectionData
            {
                $this->seen[] = $role->id;
                if ($role->is($this->failed)) {
                    throw new DoctorInspectionException;
                }

                return new RoleInspectionData(true, true, true);
            }
        },
        role_probe_vpn_inspector($vpnCalls, throws: true),
    )->inspect(role_probe_context($node));

    expect($report->checked)
        ->toBe(2)
        ->and($seen)
        ->toBe([$failed->id, $healthy->id])
        ->and($vpnCalls)
        ->toBe(0)
        ->and($report->issues)
        ->toHaveCount(1)
        ->and($report->issues[0]->code)
        ->toBe('role.inspection_failed')
        ->and($report->issues[0]->resourceId)
        ->toBe($failed->id)
        ->and(json_encode($report))
        ->not->toContain('secret exception detail');
});

it('maps a typed VPN inspection failure to the role row and does not stop later rows', function (): void {
    $node = role_probe_node('vpn-failure');
    $vpn = role_probe_assignment($node, RoleName::Vpn);
    role_probe_assignment($node, RoleName::AppDev);
    $roleCalls = 0;
    $vpnCalls = 0;
    $report = new RoleDoctorProbe(
        role_probe_state_inspector($roleCalls),
        role_probe_vpn_inspector($vpnCalls, throws: true),
    )->inspect(role_probe_context($node));

    expect($roleCalls)
        ->toBe(2)
        ->and($vpnCalls)
        ->toBe(1)
        ->and($report->issues)
        ->toHaveCount(1)
        ->and($report->issues[0]->code)
        ->toBe('role.inspection_failed')
        ->and($report->issues[0]->resourceId)
        ->toBe($vpn->id);
});

function role_probe_node(string $name): Node
{
    static $number = 0;
    $number++;

    return Node::query()->create([
        'name' => "role-probe-{$name}-{$number}",
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => "192.0.2.{$number}",
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_address' => "10.44.0.{$number}",
    ]);
}

function role_probe_assignment(
    Node $node,
    RoleName $role,
    LifecycleStatus $status = LifecycleStatus::Active,
    ?string $failedStep = null,
    ?string $errorCode = null,
): NodeRole {
    return $node->roles()->create([
        'role' => $role,
        'status' => $status,
        'failed_step' => $failedStep,
        'error_code' => $errorCode,
    ]);
}

function role_probe_context(Node $node, bool $reachable = true): DoctorNodeContext
{
    return new DoctorNodeContext($node, new NodeInspectionData($reachable, 'linux', 'x86_64', true));
}

function role_probe_state_inspector(
    int &$calls,
    ?RoleInspectionData $state = null,
): RoleStateInspector {
    return new class($calls, $state ?? new RoleInspectionData(true, true, true)) implements RoleStateInspector {
        public function __construct(
            private int &$calls,
            private RoleInspectionData $state,
        ) {}

        public function inspect(NodeRole $role): RoleInspectionData
        {
            $this->calls++;

            return $this->state;
        }
    };
}

function role_probe_vpn_inspector(
    int &$calls,
    ?GatewayVpnInspectionData $state = null,
    bool $throws = false,
): GatewayVpnStateInspector {
    return new class($calls, $state ?? new GatewayVpnInspectionData(true, true, true, true), $throws) implements
        GatewayVpnStateInspector {
        public function __construct(
            private int &$calls,
            private GatewayVpnInspectionData $state,
            private bool $throws,
        ) {}

        public function inspect(NodeRole $role): GatewayVpnInspectionData
        {
            $this->calls++;
            if ($this->throws) {
                throw new DoctorInspectionException;
            }

            return $this->state;
        }
    };
}
