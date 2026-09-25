<?php

declare(strict_types=1);

use App\Actions\Doctor\NodeDoctorProbe;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Infrastructure\Nodes\NodeAgentFootprint;
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
    $hash = hash('sha256', str_repeat('a', 64));
    $node = Node::query()->forceCreate([
        'name' => 'edge',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.50',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.2',
        'ssh_host_fingerprint' => 'SHA256:managed',
        'agent_secret_hash' => $hash,
    ]);

    return [new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'x86_64', true, true, true, true, true, $hash)), (int) $node->id];
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

    it('does not report the view for a Node whose agent is missing or not running', function (bool $unitExists, bool $active): void {
        activate_websocket_role();
        [$context] = node_agent_view_doctor_context();
        $inspection = $context->inspection;
        app(CacheAgentStateView::class)->putSubscriber(configured: true, connected: true, channels: 2);

        $codes = node_agent_view_codes(new DoctorNodeContext($context->node, new NodeInspectionData(true, 'linux', 'x86_64', true, $unitExists, $unitExists, $active, true, $inspection->agentSecretChecksum)));

        expect(array_filter($codes, static fn (string $code): bool => str_starts_with($code, 'node.agent_view_stale')))->toBe([])
            ->and($inspection->agentActive)->toBeTrue();
    })->with([
        'agent missing' => [false, false],
        'agent inactive' => [true, false],
    ]);

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

/** @return list<string> */
function node_agent_secret_codes(Node $node, ?string $checksum, bool $binaryExists = true): array
{
    $codes = node_agent_view_codes(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'x86_64', true, $binaryExists, true, true, true, $checksum)));

    return array_values(array_filter($codes, static fn (string $code): bool => str_starts_with($code, 'node.agent_secret')));
}

describe('the agent secret', function (): void {
    it('reports a missing or mismatched secret file and nothing for a matching one', function (): void {
        [$context] = node_agent_view_doctor_context();
        $node = $context->node;

        expect(node_agent_secret_codes($node, $node->agent_secret_hash))->toBe([])
            ->and(node_agent_secret_codes($node, null))->toBe(['node.agent_secret_mismatch="missing"'])
            ->and(node_agent_secret_codes($node, hash('sha256', 'another secret')))->toBe(['node.agent_secret_mismatch="mismatch"'])
            ->and(node_agent_secret_codes($node, null, binaryExists: false))->toBe([]);
    });

    it('reports a Node that must send a secret but has none recorded', function (): void {
        [$context] = node_agent_view_doctor_context();
        $node = $context->node->forceFill(['agent_secret_hash' => null, 'agent_secret_exempt' => false]);

        expect(node_agent_secret_codes($node, hash('sha256', 'any')))->toBe(['node.agent_secret_mismatch="mismatch"'])
            ->and(node_agent_secret_codes($node, null))->toBe(['node.agent_secret_mismatch="missing"']);
    });

    it('skips an exempt Node while the pinned agent sends no secret', function (): void {
        [$context] = node_agent_view_doctor_context();
        $node = $context->node->forceFill(['agent_secret_hash' => null, 'agent_secret_exempt' => true]);

        expect(NodeAgentFootprint::sendsSecret())->toBeFalse()
            ->and(node_agent_secret_codes($node, null))->toBe([]);
    });

    it('reports a Node that is still exempt once the pinned agent sends a secret', function (): void {
        [$context] = node_agent_view_doctor_context();
        $node = $context->node->forceFill(['agent_secret_hash' => null, 'agent_secret_exempt' => true]);
        $probe = new NodeDoctorProbe(agentVersion: NodeAgentFootprint::SecretSince);

        foreach ([null, hash('sha256', 'any')] as $checksum) {
            $issues = $probe->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'x86_64', true, true, true, true, true, $checksum)))->issues;
            $secret = array_values(array_filter($issues, static fn ($issue): bool => $issue->code === 'node.agent_secret_mismatch'));

            expect($secret)->toHaveCount(1)
                ->and($secret[0]->observed)->toBe('exempt')
                ->and($secret[0]->summary)->toBe('Node agent is still exempt from its secret.');
        }
    });

    it('never puts the secret hash in the report', function (): void {
        [$context] = node_agent_view_doctor_context();
        $report = (new NodeDoctorProbe)->inspect(new DoctorNodeContext($context->node, new NodeInspectionData(true, 'linux', 'x86_64', true, true, true, true, true, hash('sha256', 'other'))));

        expect(json_encode($report->toArray()))->not->toContain((string) $context->node->agent_secret_hash, hash('sha256', 'other'));
    });
});
