<?php

declare(strict_types=1);

use App\Actions\Doctor\NodeDoctorProbe;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;

it('reports node.agent_missing when the binary or unit is absent', function (bool $binaryExists, bool $unitExists): void {
    $report = (new NodeDoctorProbe)->inspect(node_agent_doctor_context(
        binaryExists: $binaryExists,
        unitExists: $unitExists,
        active: true,
        checksumMatches: false,
    ));

    $codes = array_map(static fn ($issue): string => $issue->code, $report->issues);

    expect($codes)
        ->toContain('node.agent_missing')
        ->not->toContain('node.agent_inactive');

    if ($binaryExists) {
        expect($codes)->toContain('node.agent_outdated');
    } else {
        expect($codes)->not->toContain('node.agent_outdated');
    }
})->with([
    'binary absent' => [false, true],
    'unit absent' => [true, false],
]);

it('reports node.agent_inactive when the unit exists but is not active', function (): void {
    $report = (new NodeDoctorProbe)->inspect(node_agent_doctor_context(
        binaryExists: true,
        unitExists: true,
        active: false,
        checksumMatches: true,
    ));

    expect(array_map(static fn ($issue): string => $issue->code, $report->issues))
        ->toContain('node.agent_inactive');
});

it('reports both inactive and outdated when the inactive binary differs from the pin', function (): void {
    $report = (new NodeDoctorProbe)->inspect(node_agent_doctor_context(
        binaryExists: true,
        unitExists: true,
        active: false,
        checksumMatches: false,
    ));

    expect(array_map(static fn ($issue): string => $issue->code, $report->issues))
        ->toContain('node.agent_inactive')
        ->toContain('node.agent_outdated');
});

it('reports node.agent_outdated when the binary checksum differs from the pin', function (): void {
    $report = (new NodeDoctorProbe)->inspect(node_agent_doctor_context(
        binaryExists: true,
        unitExists: true,
        active: true,
        checksumMatches: false,
    ));

    expect(array_map(static fn ($issue): string => $issue->code, $report->issues))
        ->toContain('node.agent_outdated');
});

it('skips a non-active managed node outside the install boundary', function (): void {
    $node = new Node([
        'name' => 'edge',
        'status' => LifecycleStatus::Provisioning,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.2',
        'ssh_host_fingerprint' => 'SHA256:managed',
    ]);
    $report = (new NodeDoctorProbe)->inspect(new DoctorNodeContext(
        $node,
        new NodeInspectionData(true, 'linux', 'x86_64', true, false, false, false, false),
    ));

    expect(array_map(static fn ($issue): string => $issue->code, $report->issues))
        ->toContain('node.lifecycle_not_active')
        ->not->toContain('node.agent_missing')
        ->not->toContain('node.agent_inactive')
        ->not->toContain('node.agent_outdated');
});

it('skips a node outside the managed boundary', function (): void {
    $node = new Node([
        'name' => 'operator-client',
        'status' => LifecycleStatus::Active,
        'platform' => 'darwin',
    ]);
    $report = (new NodeDoctorProbe)->inspect(new DoctorNodeContext(
        $node,
        new NodeInspectionData(true, 'darwin', 'aarch64', true, false, false, false, false),
    ));

    expect($report->issues)->toBeEmpty();
});

function node_agent_doctor_context(
    bool $binaryExists,
    bool $unitExists,
    bool $active,
    bool $checksumMatches,
): DoctorNodeContext {
    $node = new Node([
        'name' => 'edge',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.2',
        'ssh_host_fingerprint' => 'SHA256:managed',
    ]);

    return new DoctorNodeContext($node, new NodeInspectionData(
        true,
        'linux',
        'x86_64',
        true,
        $binaryExists,
        $unitExists,
        $active,
        $checksumMatches,
    ));
}
