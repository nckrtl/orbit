<?php

declare(strict_types=1);

use App\Actions\Doctor\NodeDoctorProbe;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentView\CacheAgentStateView;
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

/** @return array{DoctorNodeContext, int} */
function node_agent_view_doctor_context(): array
{
    $node = Node::query()->create([
        'name' => 'edge',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.50',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.2',
        'ssh_host_fingerprint' => 'SHA256:managed',
    ]);

    return [new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'x86_64', true, true, true, true, true)), (int) $node->id];
}

/** @return list<string> */
function node_agent_view_codes(DoctorNodeContext $context): array
{
    return array_map(static fn ($issue): string => $issue->code.'='.json_encode($issue->observed), (new NodeDoctorProbe)->inspect($context)->issues);
}

describe('the Gateway view of an active agent', function (): void {
    it('reports nothing while no websocket role is active', function (): void {
        [$context] = node_agent_view_doctor_context();

        expect(node_agent_view_codes($context))->toBe([]);
    });

    it('reports the subscriber down when it wrote no recent health', function (): void {
        activate_websocket_role();
        [$context] = node_agent_view_doctor_context();

        expect(node_agent_view_codes($context))->toBe(['node.agent_view_stale="subscriber_down"']);
    });

    it('reports a subscriber without a Reverb connection', function (): void {
        activate_websocket_role();
        [$context] = node_agent_view_doctor_context();
        app(CacheAgentStateView::class)->putSubscriber(configured: true, connected: false, channels: 0);

        expect(node_agent_view_codes($context))->toBe(['node.agent_view_stale="disconnected"']);
    });

    it('reports a missing or stale Node view and nothing for a fresh one', function (): void {
        activate_websocket_role();
        [$context, $nodeId] = node_agent_view_doctor_context();
        app(CacheAgentStateView::class)->putSubscriber(configured: true, connected: true, channels: 2);

        expect(node_agent_view_codes($context))->toBe(['node.agent_view_stale="missing"']);

        seed_agent_view($nodeId, [], ageSeconds: 16);
        expect(node_agent_view_codes($context))->toBe(['node.agent_view_stale="stale"']);

        seed_agent_view($nodeId, []);
        expect(node_agent_view_codes($context))->toBe([]);
    });
});
