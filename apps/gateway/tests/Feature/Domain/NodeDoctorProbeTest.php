<?php

declare(strict_types=1);

use App\Actions\Doctor\NodeDoctorProbe;
use App\Data\Doctor\DoctorFamilyReportData;
use App\Data\Doctor\DoctorIssueData;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use Illuminate\Database\Eloquent\Collection;

it('reports bounded node drift and unreachable state', function (): void {
    $node = new Node([
        'name' => 'edge',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'amd64',
        'wireguard_ip' => '10.44.0.2',
        'ssh_host_fingerprint' => 'SHA256:managed',
    ]);
    $report = new NodeDoctorProbe()->inspect(
        new DoctorNodeContext($node, new NodeInspectionData(false, null, null, null)),
    );
    expect($report)
        ->toBeInstanceOf(DoctorFamilyReportData::class)
        ->and($report->checked)
        ->toBe(1)
        ->and($report->issues[0]->code)
        ->toBe('node.ssh_unreachable')
        ->and($report->status->value)
        ->toBe('unverifiable')
        ->and($report->issues)
        ->toHaveCount(1)
        ->and($report->issues[0]->kind->value)
        ->toBe('unverifiable')
        ->and($report->issues[0]->expected)
        ->toBeTrue()
        ->and($report->issues[0]->observed)
        ->toBeFalse();
});

it('reports lifecycle and identity drift for a managed provisioning node', function (): void {
    $node = new Node([
        'name' => 'edge',
        'status' => LifecycleStatus::Provisioning,
        'platform' => 'linux',
        'architecture' => 'amd64',
        'wireguard_ip' => '10.44.0.2',
        'ssh_host_fingerprint' => 'SHA256:managed',
    ]);
    $report = new NodeDoctorProbe()->inspect(
        new DoctorNodeContext($node, new NodeInspectionData(true, 'darwin', 'aarch64', false)),
    );
    expect($report->status->value)
        ->toBe('drift')
        ->and($report->issues)
        ->toHaveCount(4)
        ->and(array_map(fn (DoctorIssueData $issue): string => $issue->code, $report->issues))
        ->toBe([
            'node.lifecycle_not_active',
            'node.platform_mismatch',
            'node.architecture_mismatch',
            'node.wireguard_ip_mismatch',
        ])
        ->and($report->issues[1]->kind->value)
        ->toBe('drift')
        ->and($report->issues[1]->expected)
        ->toBe('linux')
        ->and($report->issues[1]->observed)
        ->toBe('darwin')
        ->and($report->issues[2]->kind->value)
        ->toBe('drift')
        ->and($report->issues[2]->expected)
        ->toBe('x86_64')
        ->and($report->issues[2]->observed)
        ->toBe('aarch64')
        ->and($report->issues[3]->kind->value)
        ->toBe('drift')
        ->and($report->issues[3]->expected)
        ->toBeTrue()
        ->and($report->issues[3]->observed)
        ->toBeFalse();
});

it('reports bounded inspection failure before unreachable', function (): void {
    $node = new Node([
        'name' => 'edge',
        'status' => LifecycleStatus::Provisioning,
        'platform' => 'linux',
        'wireguard_ip' => '10.44.0.2',
        'ssh_host_fingerprint' => 'SHA256:managed',
    ]);
    $report = new NodeDoctorProbe()->inspect(
        new DoctorNodeContext($node, new NodeInspectionData(false, null, null, null), inspectionFailed: true),
    );
    expect($report->status->value)
        ->toBe('unverifiable')
        ->and($report->checked)
        ->toBe(1)
        ->and($report->issues[0]->code)
        ->toBe('node.lifecycle_not_active')
        ->and($report->issues[0]->kind->value)
        ->toBe('drift')
        ->and($report->issues[1]->code)
        ->toBe('node.inspection_failed')
        ->and($report->issues[1]->kind->value)
        ->toBe('unverifiable')
        ->and($report->issues[1]->expected)
        ->toBeNull()
        ->and($report->issues[1]->observed)
        ->toBeNull();
});

it('keeps unreachable observation for a fingerprint-managed node in every inactive lifecycle', function (
    LifecycleStatus $status,
): void {
    $node = new Node([
        'name' => 'edge',
        'status' => $status,
        'platform' => 'linux',
        'architecture' => 'amd64',
        'wireguard_ip' => '10.44.0.2',
        'ssh_host_fingerprint' => 'SHA256:managed',
    ]);

    $report = new NodeDoctorProbe()->inspect(
        new DoctorNodeContext($node, new NodeInspectionData(false, null, null, null)),
    );

    expect(array_map(fn (DoctorIssueData $issue): string => $issue->code, $report->issues))
        ->toBe(['node.lifecycle_not_active', 'node.ssh_unreachable']);
})->with([
    'provisioning Node' => LifecycleStatus::Provisioning,
    'failed Node' => LifecycleStatus::Failed,
    'removing Node' => LifecycleStatus::Removing,
]);

it('reports only lifecycle drift for an inactive unmanaged record', function (): void {
    $node = new Node([
        'name' => 'operator-client',
        'status' => LifecycleStatus::Failed,
        'platform' => 'linux',
        'architecture' => 'amd64',
        'wireguard_ip' => '10.44.0.2',
        'ssh_host_fingerprint' => null,
    ]);
    $node->setRelation('roles', new Collection);

    $report = new NodeDoctorProbe()->inspect(
        new DoctorNodeContext($node, new NodeInspectionData(false, null, null, null), inspectionFailed: true),
    );

    expect($report->status->value)
        ->toBe('drift')
        ->and($report->issues)
        ->toHaveCount(1)
        ->and($report->issues[0]->code)
        ->toBe('node.lifecycle_not_active');
});

it('reports a healthy node with bounded values', function (): void {
    $node = new Node([
        'name' => 'edge',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'amd64',
        'wireguard_ip' => '10.44.0.2',
        'ssh_host_fingerprint' => 'SHA256:managed',
    ]);
    $report = new NodeDoctorProbe()->inspect(
        new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'x86_64', true)),
    );
    expect($report->status->value)->toBe('healthy')->and($report->checked)->toBe(1)->and($report->issues)->toBeEmpty();
});

it('suppresses managed SSH expectations for an ineligible record', function (): void {
    $sentinel = 'credential=doctor-secret';
    $node = new Node([
        'name' => 'edge',
        'status' => LifecycleStatus::Active,
        'platform' => $sentinel,
        'architecture' => $sentinel,
    ]);

    $report = new NodeDoctorProbe()->inspect(
        new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'x86_64', true)),
    );

    expect($report->status->value)
        ->toBe('healthy')
        ->and($report->issues)
        ->toBeEmpty()
        ->and(json_encode($report, JSON_THROW_ON_ERROR))
        ->not->toContain($sentinel);
});

it('redacts an unsupported stored architecture for an eligible node', function (): void {
    $sentinel = 'credential=doctor-secret';
    $node = new Node([
        'name' => 'edge',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => $sentinel,
        'wireguard_ip' => '10.44.0.2',
        'ssh_host_fingerprint' => 'SHA256:managed',
    ]);

    $report = new NodeDoctorProbe()->inspect(
        new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'x86_64', true)),
    );

    expect($report->status->value)
        ->toBe('unverifiable')
        ->and($report->issues)
        ->toHaveCount(1)
        ->and($report->issues[0]->code)
        ->toBe('node.inspection_failed')
        ->and($report->issues[0]->kind->value)
        ->toBe('unverifiable')
        ->and($report->issues[0]->expected)
        ->toBe('supported')
        ->and($report->issues[0]->observed)
        ->toBe('unsupported')
        ->and(json_encode($report, JSON_THROW_ON_ERROR))
        ->not->toContain($sentinel);
});
