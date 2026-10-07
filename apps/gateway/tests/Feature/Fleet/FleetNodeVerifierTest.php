<?php

declare(strict_types=1);

use App\Actions\Doctor\RunDoctorAction;
use App\Domain\Broadcasting\RealtimeConnection;
use App\Domain\Doctor\NodeDiskFilesystemData;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Doctor\NodeStateInspector;
use App\Domain\Fleet\FleetNodeVerifier;
use App\Infrastructure\AgentView\AgentReportedVersions;
use App\Models\Node;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Tests\Support\Fleet\FleetFixtures;

final class FleetVerifierInspector implements NodeStateInspector
{
    public function __construct(public NodeInspectionData $inspection) {}

    public function inspect(Node $node): NodeInspectionData
    {
        return $this->inspection;
    }
}

function fleetVerifierInspection(bool $agentActive = true, bool $diskLow = false): NodeInspectionData
{
    return new NodeInspectionData(
        reachable: true,
        platform: 'Linux',
        architecture: 'x86_64',
        wireGuardAddressMatches: true,
        agentBinaryExists: true,
        agentUnitExists: true,
        agentActive: $agentActive,
        agentChecksumMatches: true,
        agentSecretChecksum: str_repeat('e', 64),
        diskFilesystems: $diskLow ? [new NodeDiskFilesystemData('/', 10, 100_000_000, 1000, 10_000)] : [],
    );
}

function fleetVerifierNode(): Node
{
    $node = FleetFixtures::node('dev');
    $node->forceFill(['agent_secret_hash' => str_repeat('e', 64)])->save();

    return $node;
}

describe('fleet Node verify', function (): void {
    it('passes a Node whose node and role families report no issue', function (): void {
        app()->instance(NodeStateInspector::class, new FleetVerifierInspector(fleetVerifierInspection()));
        $node = fleetVerifierNode();
        $verifier = app(FleetNodeVerifier::class);

        $result = $verifier->verify($node, $verifier->baseline($node), '0.3.0');

        expect($result['passed'])->toBeTrue()
            ->and($result['new_issues'])->toBe([])
            ->and($result['presence'])->toBe('unavailable');
    });

    it('keeps an issue from before the rollout that the rollout does not own', function (): void {
        $inspector = new FleetVerifierInspector(fleetVerifierInspection(diskLow: true));
        app()->instance(NodeStateInspector::class, $inspector);
        $node = fleetVerifierNode();
        $verifier = app(FleetNodeVerifier::class);
        $baseline = $verifier->baseline($node);

        $result = $verifier->verify($node, $baseline, '0.3.0');

        expect($result['passed'])->toBeTrue()
            ->and($result['preexisting_issues'])->toBe(['node.disk_low:node:'.$node->id]);
    });

    it('fails on an issue the rollout owns, even one from before the rollout', function (): void {
        app()->instance(NodeStateInspector::class, new FleetVerifierInspector(fleetVerifierInspection(agentActive: false)));
        $node = fleetVerifierNode();
        $verifier = new FleetNodeVerifier(app(RunDoctorAction::class), app(AgentReportedVersions::class), app(RealtimeConnection::class), sleep: static function (int $seconds): void {});
        $baseline = $verifier->baseline($node);

        $result = $verifier->verify($node, $baseline, '0.3.0');

        expect($result['passed'])->toBeFalse()
            ->and(array_column($result['new_issues'], 'code'))->toBe(['node.agent_inactive']);
    });
});

describe('fleet Node verify presence', function (): void {
    beforeEach(function (): void {
        app()->instance(AgentReportedVersions::class, new AgentReportedVersions(new Repository(new ArrayStore)));
    });

    function presenceVerifier(): FleetNodeVerifier
    {
        return new FleetNodeVerifier(
            app(RunDoctorAction::class),
            app(AgentReportedVersions::class),
            app(RealtimeConnection::class),
            presenceWaitSeconds: 0,
            sleep: static function (int $seconds): void {},
        );
    }

    it('fails a Node whose agent reports another version than the pin', function (): void {
        app()->instance(NodeStateInspector::class, new FleetVerifierInspector(fleetVerifierInspection()));
        $node = fleetVerifierNode();
        activate_websocket_role(FleetFixtures::node('reverb'));
        app(AgentReportedVersions::class)->record($node->id, '0.2.0');

        $verifier = presenceVerifier();
        $result = $verifier->verify($node, $verifier->baseline($node), '0.3.0');

        expect($result['new_issues'])->toBe([])
            ->and($result['presence'])->toBe('mismatch')
            ->and($result['agent_version'])->toBe('0.2.0')
            ->and($result['passed'])->toBeFalse();
    });

    it('lets Doctor stand in when the agent never reported a version', function (): void {
        app()->instance(NodeStateInspector::class, new FleetVerifierInspector(fleetVerifierInspection()));
        $node = fleetVerifierNode();
        activate_websocket_role(FleetFixtures::node('reverb'));

        $verifier = presenceVerifier();
        $result = $verifier->verify($node, $verifier->baseline($node), '0.3.0');

        expect($result['new_issues'])->toBe([])->and($result['presence'])->toBe('unavailable')
            ->and($result['passed'])->toBeTrue();
    });

    it('keeps a reported version for as long as the agent stays connected', function (): void {
        $versions = app(AgentReportedVersions::class);
        $versions->record(7, '0.3.0');

        $this->travel(30)->days();

        expect($versions->get(7)['version'] ?? null)->toBe('0.3.0');
    });
});
