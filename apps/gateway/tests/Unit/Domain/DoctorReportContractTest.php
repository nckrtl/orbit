<?php

declare(strict_types=1);

use App\Data\Doctor\DoctorFamilyReportData;
use App\Data\Doctor\DoctorIssueData;
use App\Data\Doctor\DoctorNodeReportData;
use App\Data\Doctor\DoctorReportData;
use App\Domain\Doctor\AppDoctorIssueCode;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\DoctorIssueCodeCatalog;
use App\Domain\Doctor\DoctorIssueKind;
use App\Domain\Doctor\FirewallDoctorIssueCode;
use App\Domain\Doctor\InstanceDoctorIssueCode;
use App\Domain\Doctor\NodeDoctorIssueCode;
use App\Domain\Doctor\ProcessDoctorIssueCode;
use App\Domain\Doctor\RoleDoctorIssueCode;
use App\Domain\Doctor\ToolDoctorIssueCode;
use App\Domain\Doctor\WorkspaceDoctorIssueCode;
use Tests\TestCase;

uses(TestCase::class);

it('serializes bounded doctor reports and derives status precedence', function (): void {
    $drift = new DoctorIssueData(
        WorkspaceDoctorIssueCode::BranchMismatch,
        DoctorIssueKind::Drift,
        'workspace',
        31,
        'feature-a',
        'Mismatch.',
        'managed',
        'mismatch',
    );
    $unverifiable = new DoctorIssueData(
        WorkspaceDoctorIssueCode::InspectionFailed,
        DoctorIssueKind::Unverifiable,
        'workspace',
        31,
        'feature-a',
        'Unavailable.',
        'known',
        null,
    );
    $secondDrift = new DoctorIssueData(
        WorkspaceDoctorIssueCode::DocumentRootMissing,
        DoctorIssueKind::Drift,
        'workspace',
        31,
        'feature-a',
        'Root mismatch.',
        'managed',
        'mismatch',
    );

    $family = DoctorFamilyReportData::fromIssues(DoctorFamily::Workspace, 1, [$drift, $secondDrift, $unverifiable]);
    $node = DoctorNodeReportData::fromFamilies(7, 'app-1', [$family]);
    $report = DoctorReportData::fromNodes([$node]);

    expect(array_map(static fn (DoctorFamily $family): string => $family->value, DoctorFamily::cases()))
        ->toEqual(['node', 'role', 'app', 'instance', 'workspace', 'tool', 'process', 'firewall'])
        ->and($family->status->value)
        ->toBe('unverifiable')
        ->and($family->family)
        ->toBe(DoctorFamily::Workspace)
        ->and($family->checked)
        ->toBe(1)
        ->and($family->issues)
        ->toHaveCount(3)
        ->and($node->healthy)
        ->toBeFalse()
        ->and($report->healthy)
        ->toBeFalse()
        ->and($report->summary)
        ->toBe(['nodes' => 1, 'families' => 1, 'checks' => 1, 'drift' => 2, 'unverifiable' => 1])
        ->and($family->issues[0]->resourceType)
        ->toBe('workspace')
        ->and($family->issues[0]->resourceId)
        ->toBe(31)
        ->and($unverifiable->observed)
        ->toBeNull()
        ->and(json_decode(
            json: json_encode($report, JSON_THROW_ON_ERROR),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        ))
        ->toBe([
            'healthy' => false,
            'nodes' => [[
                'node_id' => 7,
                'node_name' => 'app-1',
                'healthy' => false,
                'families' => [[
                    'family' => 'workspace',
                    'status' => 'unverifiable',
                    'checked' => 1,
                    'issues' => [
                        [
                            'code' => 'workspace.branch_mismatch',
                            'kind' => 'drift',
                            'resource_type' => 'workspace',
                            'resource_id' => 31,
                            'resource_name' => 'feature-a',
                            'summary' => 'Mismatch.',
                            'expected' => 'managed',
                            'observed' => 'mismatch',
                        ],
                        [
                            'code' => 'workspace.document_root_missing',
                            'kind' => 'drift',
                            'resource_type' => 'workspace',
                            'resource_id' => 31,
                            'resource_name' => 'feature-a',
                            'summary' => 'Root mismatch.',
                            'expected' => 'managed',
                            'observed' => 'mismatch',
                        ],
                        [
                            'code' => 'workspace.inspection_failed',
                            'kind' => 'unverifiable',
                            'resource_type' => 'workspace',
                            'resource_id' => 31,
                            'resource_name' => 'feature-a',
                            'summary' => 'Unavailable.',
                            'expected' => 'known',
                            'observed' => null,
                        ],
                    ],
                ]],
            ]],
            'summary' => ['nodes' => 1, 'families' => 1, 'checks' => 1, 'drift' => 2, 'unverifiable' => 1],
        ])
        ->and(new DoctorInspectionException()->getMessage())
        ->toBe('');
});

it('maps unknown internal issue codes to the family inspection failure', function (): void {
    expect(DoctorIssueCodeCatalog::fromInternal(DoctorFamily::Workspace, 'workspace.branch_mismatch'))
        ->toBe(WorkspaceDoctorIssueCode::BranchMismatch)
        ->and(DoctorIssueCodeCatalog::fromInternal(DoctorFamily::Workspace, 'workspace.secret-sentinel'))
        ->toBe(WorkspaceDoctorIssueCode::InspectionFailed);
});

it('replaces unknown internal issue values with a bounded unverifiable finding', function (): void {
    $sentinel = 'credential=doctor-secret';

    $issue = DoctorIssueData::fromInternal(
        DoctorFamily::Workspace,
        "workspace.{$sentinel}",
        DoctorIssueKind::Drift,
        31,
        'feature-a',
        $sentinel,
        $sentinel,
        $sentinel,
    );

    expect($issue->code)
        ->toBe('workspace.inspection_failed')
        ->and($issue->kind)
        ->toBe(DoctorIssueKind::Unverifiable)
        ->and($issue->summary)
        ->toBe('Workspace inspection could not be verified.')
        ->and($issue->expected)
        ->toBe('verifiable')
        ->and($issue->observed)
        ->toBe('unverifiable')
        ->and(json_encode($issue, JSON_THROW_ON_ERROR))
        ->not->toContain($sentinel);
});

it('defines the exact stable issue-code catalog for every Doctor family', function (): void {
    $catalogs = [
        DoctorFamily::Node->value => NodeDoctorIssueCode::cases(),
        DoctorFamily::Role->value => RoleDoctorIssueCode::cases(),
        DoctorFamily::App->value => AppDoctorIssueCode::cases(),
        DoctorFamily::Instance->value => InstanceDoctorIssueCode::cases(),
        DoctorFamily::Workspace->value => WorkspaceDoctorIssueCode::cases(),
        DoctorFamily::Tool->value => ToolDoctorIssueCode::cases(),
        DoctorFamily::Process->value => ProcessDoctorIssueCode::cases(),
        DoctorFamily::Firewall->value => FirewallDoctorIssueCode::cases(),
    ];

    expect(array_map(
        static fn (array $codes): array => array_map(static fn ($code): string => $code->code(), $codes),
        $catalogs,
    ))->toBe([
        'node' => [
            'node.lifecycle_not_active',
            'node.ssh_unreachable',
            'node.platform_mismatch',
            'node.architecture_mismatch',
            'node.wireguard_ip_mismatch',
            'node.inspection_failed',
        ],
        'role' => [
            'role.lifecycle_not_active',
            'role.assignment_conflict',
            'role.singleton_conflict',
            'role.cluster_ownership_mismatch',
            'role.cluster_cardinality_conflict',
            'role.packages_missing',
            'role.services_inactive',
            'role.firewall_projection_mismatch',
            'role.vpn_inactive',
            'role.vpn_projection_mismatch',
            'role.dns_projection_mismatch',
            'role.dns_snippet_conflict',
            'role.inspection_failed',
            'role.node_unreachable',
        ],
        'app' => ['app.repository_origin_mismatch', 'app.inspection_failed', 'app.node_unreachable'],
        'instance' => [
            'instance.lifecycle_not_active',
            'instance.checkout_missing',
            'instance.repository_not_independent',
            'instance.origin_mismatch',
            'instance.source_identity_mismatch',
            'instance.registered_worktree_unavailable',
            'instance.inspection_failed',
            'instance.node_unreachable',
        ],
        'workspace' => [
            'workspace.lifecycle_not_active',
            'workspace.checkout_missing',
            'workspace.worktree_missing',
            'workspace.branch_mismatch',
            'workspace.document_root_missing',
            'workspace.caddy_projection_mismatch',
            'workspace.php_fpm_projection_mismatch',
            'workspace.certificate_projection_mismatch',
            'workspace.dns_projection_mismatch',
            'workspace.inspection_failed',
            'workspace.node_unreachable',
        ],
        'tool' => ['tool.not_installed', 'tool.version_mismatch', 'tool.inspection_failed', 'tool.node_unreachable'],
        'process' => [
            'process.runtime_missing',
            'process.state_mismatch',
            'process.inspection_failed',
            'process.node_unreachable',
        ],
        'firewall' => [
            'firewall.lifecycle_not_active',
            'firewall.backend_inactive',
            'firewall.rule_missing',
            'firewall.rule_mismatch',
            'firewall.inspection_failed',
            'firewall.node_unreachable',
        ],
    ]);

    foreach ($catalogs as $family => $codes) {
        foreach ($codes as $code) {
            expect($code->family()->value)
                ->toBe($family)
                ->and(DoctorIssueCodeCatalog::fromInternal($code->family(), $code->code()))
                ->toBe($code);
        }
    }
});
