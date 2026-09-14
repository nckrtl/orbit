<?php

declare(strict_types=1);

use App\Data\Doctor\DoctorFamilyReportData;
use App\Data\Doctor\DoctorIssueData;
use App\Data\Doctor\DoctorNodeReportData;
use App\Data\Doctor\DoctorReportData;
use App\Domain\Doctor\AppDoctorIssueCode;
use App\Domain\Doctor\DatabaseConnectionDoctorIssueCode;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\DoctorIssueCodeCatalog;
use App\Domain\Doctor\DoctorIssueKind;
use App\Domain\Doctor\FirewallDoctorIssueCode;
use App\Domain\Doctor\HerdrSessionDoctorIssueCode;
use App\Domain\Doctor\InstanceDoctorIssueCode;
use App\Domain\Doctor\NodeDoctorIssueCode;
use App\Domain\Doctor\ProcessDoctorIssueCode;
use App\Domain\Doctor\RoleDoctorIssueCode;
use App\Domain\Doctor\ScheduleDoctorIssueCode;
use App\Domain\Doctor\ToolDoctorIssueCode;
use Tests\TestCase;

uses(TestCase::class);

it('serializes bounded doctor reports and derives status precedence', function (): void {
    $drift = new DoctorIssueData(
        InstanceDoctorIssueCode::OriginMismatch,
        DoctorIssueKind::Drift,
        'instance',
        31,
        'feature-a',
        'Mismatch.',
        'managed',
        'mismatch',
    );
    $unverifiable = new DoctorIssueData(
        InstanceDoctorIssueCode::InspectionFailed,
        DoctorIssueKind::Unverifiable,
        'instance',
        31,
        'feature-a',
        'Unavailable.',
        'known',
        null,
    );
    $secondDrift = new DoctorIssueData(
        InstanceDoctorIssueCode::CheckoutMissing,
        DoctorIssueKind::Drift,
        'instance',
        31,
        'feature-a',
        'Root mismatch.',
        'managed',
        'mismatch',
    );

    $family = DoctorFamilyReportData::fromIssues(DoctorFamily::Instance, 1, [$drift, $secondDrift, $unverifiable]);
    $node = DoctorNodeReportData::fromFamilies(7, 'app-1', [$family]);
    $report = DoctorReportData::fromNodes([$node]);

    expect(array_map(static fn (DoctorFamily $family): string => $family->value, DoctorFamily::cases()))
        ->toEqual(['node', 'role', 'app', 'instance', 'schedule', 'tool', 'process', 'firewall', 'herdr', 'database_connection'])
        ->and($family->status->value)
        ->toBe('unverifiable')
        ->and($family->family)
        ->toBe(DoctorFamily::Instance)
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
        ->toBe('instance')
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
                    'family' => 'instance',
                    'status' => 'unverifiable',
                    'checked' => 1,
                    'issues' => [
                        [
                            'code' => 'instance.origin_mismatch',
                            'kind' => 'drift',
                            'resource_type' => 'instance',
                            'resource_id' => 31,
                            'resource_name' => 'feature-a',
                            'summary' => 'Mismatch.',
                            'expected' => 'managed',
                            'observed' => 'mismatch',
                        ],
                        [
                            'code' => 'instance.checkout_missing',
                            'kind' => 'drift',
                            'resource_type' => 'instance',
                            'resource_id' => 31,
                            'resource_name' => 'feature-a',
                            'summary' => 'Root mismatch.',
                            'expected' => 'managed',
                            'observed' => 'mismatch',
                        ],
                        [
                            'code' => 'instance.inspection_failed',
                            'kind' => 'unverifiable',
                            'resource_type' => 'instance',
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
    expect(DoctorIssueCodeCatalog::fromInternal(DoctorFamily::Instance, 'instance.origin_mismatch'))
        ->toBe(InstanceDoctorIssueCode::OriginMismatch)
        ->and(DoctorIssueCodeCatalog::fromInternal(DoctorFamily::Instance, 'instance.php_fpm_projection_mismatch'))
        ->toBe(InstanceDoctorIssueCode::PhpFpmProjectionMismatch)
        ->and(DoctorIssueCodeCatalog::fromInternal(DoctorFamily::Instance, 'instance.secret-sentinel'))
        ->toBe(InstanceDoctorIssueCode::InspectionFailed);
});

it('replaces unknown internal issue values with a bounded unverifiable finding', function (): void {
    $sentinel = 'credential=doctor-secret';

    $issue = DoctorIssueData::fromInternal(
        DoctorFamily::Instance,
        "instance.{$sentinel}",
        DoctorIssueKind::Drift,
        31,
        'feature-a',
        $sentinel,
        $sentinel,
        $sentinel,
    );

    expect($issue->code)
        ->toBe('instance.inspection_failed')
        ->and($issue->kind)
        ->toBe(DoctorIssueKind::Unverifiable)
        ->and($issue->summary)
        ->toBe('Instance inspection could not be verified.')
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
        DoctorFamily::Schedule->value => ScheduleDoctorIssueCode::cases(),
        DoctorFamily::Tool->value => ToolDoctorIssueCode::cases(),
        DoctorFamily::Process->value => ProcessDoctorIssueCode::cases(),
        DoctorFamily::Firewall->value => FirewallDoctorIssueCode::cases(),
        DoctorFamily::Herdr->value => HerdrSessionDoctorIssueCode::cases(),
        DoctorFamily::DatabaseConnection->value => DatabaseConnectionDoctorIssueCode::cases(),
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
            'instance.source_layout_mismatch',
            'instance.checkout_missing',
            'instance.repository_layout_mismatch',
            'instance.migration_required',
            'instance.origin_mismatch',
            'instance.source_identity_mismatch',
            'instance.production_home_mismatch',
            'instance.release_selection_mismatch',
            'instance.selected_release_root_mismatch',
            'instance.environment_projection_mismatch',
            'instance.php_fpm_association_missing',
            'instance.php_fpm_association_shared',
            'instance.php_fpm_projection_mismatch',
            'instance.caddy_projection_mismatch',
            'instance.inspection_failed',
            'instance.node_unreachable',
        ],
        'schedule' => [
            'schedule.artifact_missing',
            'schedule.artifact_permissions_mismatch',
            'schedule.specification_mismatch',
            'schedule.timer_state_mismatch',
            'schedule.calendar_mismatch',
            'schedule.execution_context_mismatch',
            'schedule.completion_callback_mismatch',
            'schedule.placement_mismatch',
            'schedule.orphan_artifact',
            'schedule.node_unreachable',
            'schedule.inspection_failed',
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
        'herdr' => [
            'herdr.process_unhealthy',
            'herdr.listener_unhealthy',
            'herdr.session_unhealthy',
            'herdr.inspection_failed',
            'herdr.node_unreachable',
        ],
        'database_connection' => [
            'database_connection.missing',
            'database_connection.unhealthy',
            'database_connection.env_mismatch',
            'database_connection.inspection_failed',
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
