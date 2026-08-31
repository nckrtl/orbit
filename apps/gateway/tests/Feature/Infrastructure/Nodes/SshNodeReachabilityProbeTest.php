<?php

declare(strict_types=1);

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Doctor\NodeStateInspector;
use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Nodes\NodeReachabilityProbe;
use App\Infrastructure\Nodes\SshNodeReachabilityProbe;
use App\Models\Node;

it('reports no degradation for a node that answers', function (): void {
    $probe = new SshNodeReachabilityProbe(
        reachability_inspector(new NodeInspectionData(true, 'linux', 'x86_64', true)),
    );

    expect($probe->degradation(reachability_node()))->toBeNull();
});

it('reports the shared unreachable degradation for a node that does not answer', function (): void {
    $probe = new SshNodeReachabilityProbe(
        reachability_inspector(new NodeInspectionData(false, null, null, null)),
    );

    expect($probe->degradation(reachability_node()))->toBe(ExporterDegradationReason::Unreachable);
});

it('treats an unreadable answer as a reachable node so teardown still fails closed', function (): void {
    $probe = new SshNodeReachabilityProbe(reachability_inspector(new DoctorInspectionException));

    expect($probe->degradation(reachability_node()))->toBeNull();
});

it('binds the probe to the SSH implementation', function (): void {
    expect(app(NodeReachabilityProbe::class))->toBeInstanceOf(SshNodeReachabilityProbe::class);
});

function reachability_node(): Node
{
    return new Node([
        'name' => 'app-prod',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.3',
    ]);
}

function reachability_inspector(NodeInspectionData|Throwable $outcome): NodeStateInspector
{
    return new class($outcome) implements NodeStateInspector {
        public function __construct(
            private readonly NodeInspectionData|Throwable $outcome,
        ) {}

        public function inspect(Node $node): NodeInspectionData
        {
            if ($this->outcome instanceof Throwable) {
                throw $this->outcome;
            }

            return $this->outcome;
        }
    };
}
