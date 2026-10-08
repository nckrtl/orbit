<?php

declare(strict_types=1);

use App\Actions\Doctor\NodeDoctorProbe;
use App\Data\Doctor\DoctorFamilyReportData;
use App\Data\Doctor\DoctorIssueData;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\NodeDiskFilesystemData;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\RouteRemovalResidue;
use Illuminate\Database\Eloquent\Collection;

it('reports disk low for scarce space or inodes on managed Nodes, including the Gateway', function (int $freeKiB, int $freeInodes, bool $low): void {
    $node = new Node([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'amd64',
        'wireguard_ip' => '10.44.0.1',
        'ssh_host_fingerprint' => 'SHA256:managed',
    ]);
    $report = new NodeDoctorProbe()->inspect(new DoctorNodeContext(
        $node,
        new NodeInspectionData(true, 'linux', 'x86_64', true, diskFilesystems: [
            new NodeDiskFilesystemData('root', $freeKiB, 25 * 1024 * 1024, $freeInodes, 100000),
        ]),
    ));

    $diskIssues = array_values(array_filter($report->issues, static fn (DoctorIssueData $issue): bool => $issue->code === 'node.disk_low'));
    expect($diskIssues)->toHaveCount($low ? 1 : 0);
    if ($low) {
        expect($diskIssues[0]->kind->value)->toBe('drift')
            ->and($diskIssues[0]->observed)->toContain((string) $freeKiB);
    }
})->with([
    '16 MiB free on a 25 GiB disk' => [16 * 1024, 90000, true],
    '16 GiB free on a 25 GiB disk' => [16 * 1024 * 1024, 90000, false],
    'scarce free inodes' => [16 * 1024 * 1024, 9999, true],
    'less than ten percent free space' => [2 * 1024 * 1024, 90000, true],
    'exactly ten percent free space' => [intdiv(25 * 1024 * 1024, 10), 90000, false],
    'exactly ten percent free inodes' => [16 * 1024 * 1024, 10000, false],
]);

it('reports disk low on a separate home filesystem without exposing the device', function (): void {
    $node = new Node([
        'name' => 'edge',
        'status' => LifecycleStatus::Provisioning,
        'platform' => 'linux',
        'architecture' => 'amd64',
        'wireguard_ip' => '10.44.0.2',
        'ssh_host_fingerprint' => 'SHA256:managed',
    ]);
    $report = new NodeDoctorProbe()->inspect(new DoctorNodeContext($node, new NodeInspectionData(
        true, 'linux', 'x86_64', true,
        diskFilesystems: [
            new NodeDiskFilesystemData('root', 16 * 1024 * 1024, 25 * 1024 * 1024, 90000, 100000),
            new NodeDiskFilesystemData('home', 16384, 25 * 1024 * 1024, 90000, 100000),
        ],
    )));
    $issues = array_values(array_filter($report->issues, static fn (DoctorIssueData $issue): bool => $issue->code === 'node.disk_low'));
    expect($issues)->toHaveCount(1)
        ->and($issues[0]->observed)->toContain('home: 16384 KiB free')
        ->and(json_encode($report, JSON_THROW_ON_ERROR))->not->toContain('/dev/');
});

it('reports what an offline Route removal left on a Node, also while the Node is unreachable', function (): void {
    $node = Node::query()->create([
        'name' => 'beast',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'amd64',
        'public_ssh_host' => '192.0.2.7',
        'wireguard_ip' => '10.44.0.7',
        'ssh_host_fingerprint' => 'SHA256:managed',
    ]);
    RouteRemovalResidue::query()->create([
        'node_id' => $node->id,
        'route_id' => 171,
        'domain' => 'task-342.acme.beast.test',
        'steps' => ['caddy', 'php', 'firewall'],
    ]);

    $report = new NodeDoctorProbe()->inspect(
        new DoctorNodeContext($node, new NodeInspectionData(false, null, null, null)),
    );
    $issues = array_values(array_filter(
        $report->issues,
        static fn (DoctorIssueData $issue): bool => $issue->code === 'node.route_residue_retained',
    ));

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->kind->value)->toBe('drift')
        ->and($issues[0]->resourceName)->toBe('beast')
        ->and($issues[0]->summary)->toBe('Removed Route [171] [task-342.acme.beast.test] still has projections on the Node.')
        ->and($issues[0]->expected)->toBe('removed')
        ->and($issues[0]->observed)->toBe('caddy,php,firewall')
        ->and(collect($report->issues)->pluck('code')->all())->toContain('node.ssh_unreachable');
});

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

it('reports no agent-secret issue for an unmanaged node without a stored secret', function (): void {
    $node = new Node([
        'name' => 'operator-client',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'agent_secret_hash' => null,
    ]);
    $node->setRelation('roles', new Collection);

    $report = new NodeDoctorProbe()->inspect(new DoctorNodeContext(
        $node,
        new NodeInspectionData(true, 'linux', 'x86_64', true, true, true, true, true),
    ));

    expect(array_map(static fn (DoctorIssueData $issue): string => $issue->code, $report->issues))
        ->not->toContain('node.agent_secret_mismatch');
});

it('reports agent binary mismatch for a managed Node', function (): void {
    $node = new Node([
        'name' => 'edge',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'amd64',
        'wireguard_ip' => '10.44.0.2',
        'ssh_host_fingerprint' => 'SHA256:managed',
    ]);

    $report = new NodeDoctorProbe()->inspect(new DoctorNodeContext(
        $node,
        new NodeInspectionData(true, 'linux', 'x86_64', true, true, true, true, false),
    ));

    expect($report->status->value)
        ->toBe('drift')
        ->and(array_map(static fn (DoctorIssueData $issue): string => $issue->code, $report->issues))
        ->toContain('node.agent_binary_mismatch');

    $issue = collect($report->issues)->firstWhere('code', 'node.agent_binary_mismatch');
    expect($issue)
        ->not->toBeNull()
        ->and($issue->kind->value)
        ->toBe('drift')
        ->and($issue->summary)
        ->toBe('Node agent binary does not match the pinned checksum.');
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

it('observes a verified mac without treating a missing agent as drift', function (): void {
    $node = new Node([
        'name' => 'mini',
        'status' => LifecycleStatus::Active,
        'platform' => 'macos',
        'architecture' => 'arm64',
        'wireguard_ip' => '10.44.0.8',
        'ssh_host_fingerprint' => 'SHA256:managed',
    ]);
    $report = new NodeDoctorProbe()->inspect(new DoctorNodeContext(
        $node,
        new NodeInspectionData(
            true,
            'darwin',
            'aarch64',
            true,
            false,
            false,
            false,
            false,
            diskFilesystems: [
                new NodeDiskFilesystemData('home', 16 * 1024 * 1024, 25 * 1024 * 1024, null, null),
            ],
        ),
    ));

    expect($report->status->value)->toBe('healthy')
        ->and($report->issues)->toBeEmpty();
});

it('reports mac platform, architecture, tunnel, and home-volume drift', function (string $code, NodeInspectionData $inspection): void {
    $node = new Node([
        'name' => 'mini',
        'status' => LifecycleStatus::Active,
        'platform' => 'macos',
        'architecture' => 'arm64',
        'wireguard_ip' => '10.44.0.8',
        'ssh_host_fingerprint' => 'SHA256:managed',
    ]);
    $report = new NodeDoctorProbe()->inspect(new DoctorNodeContext($node, $inspection));
    $issue = collect($report->issues)->firstWhere('code', $code);

    expect($report->status->value)->toBe('drift')
        ->and($issue)->not->toBeNull()
        ->and(array_map(static fn (DoctorIssueData $row): string => $row->code, $report->issues))
        ->not->toContain('node.agent_missing');
})->with([
    'platform mismatch' => [
        'node.platform_mismatch',
        new NodeInspectionData(true, 'linux', 'aarch64', true, diskFilesystems: [
            new NodeDiskFilesystemData('home', 16 * 1024 * 1024, 25 * 1024 * 1024, null, null),
        ]),
    ],
    'architecture mismatch' => [
        'node.architecture_mismatch',
        new NodeInspectionData(true, 'darwin', 'x86_64', true, diskFilesystems: [
            new NodeDiskFilesystemData('home', 16 * 1024 * 1024, 25 * 1024 * 1024, null, null),
        ]),
    ],
    'tunnel mismatch' => [
        'node.wireguard_ip_mismatch',
        new NodeInspectionData(true, 'darwin', 'aarch64', false, diskFilesystems: [
            new NodeDiskFilesystemData('home', 16 * 1024 * 1024, 25 * 1024 * 1024, null, null),
        ]),
    ],
    'low home volume' => [
        'node.disk_low',
        new NodeInspectionData(true, 'darwin', 'aarch64', true, diskFilesystems: [
            new NodeDiskFilesystemData('home', 16384, 25 * 1024 * 1024, null, null),
        ]),
    ],
]);

it('reports a mac that cannot be reached or read without an agent finding', function (bool $failed): void {
    $node = new Node([
        'name' => 'mini',
        'status' => LifecycleStatus::Active,
        'platform' => 'macos',
        'architecture' => 'arm64',
        'wireguard_ip' => '10.44.0.8',
        'ssh_host_fingerprint' => 'SHA256:managed',
    ]);
    $report = new NodeDoctorProbe()->inspect(new DoctorNodeContext(
        $node,
        new NodeInspectionData(false, null, null, null),
        inspectionFailed: $failed,
    ));

    expect(array_map(static fn (DoctorIssueData $issue): string => $issue->code, $report->issues))
        ->toBe([$failed ? 'node.inspection_failed' : 'node.ssh_unreachable'])
        ->not->toContain('node.agent_missing');
})->with([
    'unreachable' => [false],
    'malformed observation' => [true],
]);
