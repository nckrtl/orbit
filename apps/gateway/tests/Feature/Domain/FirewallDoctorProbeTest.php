<?php

declare(strict_types=1);

use App\Actions\Doctor\FirewallDoctorProbe;
use App\Data\Doctor\DoctorIssueData;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Firewall\FirewallBackendStatus;
use App\Domain\Firewall\FirewallInspectionData;
use App\Domain\Firewall\FirewallInspectionShape;
use App\Domain\Firewall\FirewallInspectionTarget;
use App\Domain\Firewall\FirewallInspector;
use App\Domain\Firewall\FirewallRuleInspectionStatus;
use App\Domain\Metrics\MetricsFirewallExpectationProvider;
use App\Domain\Shared\LifecycleStatus;
use App\Models\FirewallRule;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('defines the firewall doctor probe', function (): void {
    expect(class_exists(FirewallDoctorProbe::class))->toBeTrue();
});

it('returns a healthy empty report without calling the inspector', function (): void {
    $node = Node::create([
        'name' => 'empty',
        'platform' => 'linux',
        'wireguard_ip' => '10.0.0.9',
        'public_ssh_host' => '192.0.2.9',
        'user' => 'orbit',
    ]);
    $calls = 0;
    $report = new FirewallDoctorProbe(new class($calls) implements FirewallInspector {
        public function __construct(
            private int &$calls,
        ) {}

        public function inspect(FirewallInspectionTarget $target): FirewallInspectionData
        {
            $this->calls++;
            throw new DoctorInspectionException;
        }
    }, new FirewallExpectationProviderFake)->inspect(
        new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', null, true)),
    );
    expect($report->checked)->toBe(0)->and($report->issues)->toBeEmpty()->and($calls)->toBe(0);
});

it('short-circuits unreachable nodes without inspector calls', function (): void {
    $node = Node::create([
        'name' => 'down',
        'platform' => 'linux',
        'wireguard_ip' => '10.0.0.8',
        'public_ssh_host' => '192.0.2.8',
        'user' => 'orbit',
    ]);
    FirewallRule::create([
        'node_id' => $node->id,
        'name' => 'web',
        'action' => 'allow',
        'source' => 'any',
        'protocol' => 'tcp',
        'port' => '443',
        'status' => LifecycleStatus::Active,
    ]);
    $calls = 0;
    $report = new FirewallDoctorProbe(new class($calls) implements FirewallInspector {
        public function __construct(
            private int &$calls,
        ) {}

        public function inspect(FirewallInspectionTarget $target): FirewallInspectionData
        {
            $this->calls++;
            throw new DoctorInspectionException;
        }
    }, new FirewallExpectationProviderFake)->inspect(
        new DoctorNodeContext($node, new NodeInspectionData(false, null, null, null)),
    );
    expect($report->checked)
        ->toBe(1)
        ->and($report->issues[0]->code)
        ->toBe('firewall.node_unreachable')
        ->and($calls)
        ->toBe(0);
});

it('checks real database rows in id order and emits bounded lifecycle issues', function (): void {
    $node = Node::create([
        'name' => 'node',
        'platform' => 'linux',
        'wireguard_ip' => '10.0.0.1',
        'public_ssh_host' => '192.0.2.1',
        'user' => 'orbit',
    ]);
    $late = FirewallRule::create([
        'node_id' => $node->id,
        'name' => 'late',
        'action' => 'allow',
        'source' => 'any',
        'protocol' => 'tcp',
        'port' => '443',
        'status' => LifecycleStatus::Provisioning,
    ]);
    $early = FirewallRule::create([
        'node_id' => $node->id,
        'name' => 'early',
        'action' => 'allow',
        'source' => 'any',
        'protocol' => 'tcp',
        'port' => '80',
        'status' => LifecycleStatus::Provisioning,
    ]);
    $context = new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', null, true));

    $report = new FirewallDoctorProbe(new class implements FirewallInspector {
        public function inspect(FirewallInspectionTarget $target): FirewallInspectionData
        {
            throw new DoctorInspectionException;
        }
    }, new FirewallExpectationProviderFake)->inspect($context);

    expect($report->checked)
        ->toBe(2)
        ->and($report->issues)
        ->toHaveCount(2)
        ->and(array_map(static fn (DoctorIssueData $issue): mixed => $issue->code, $report->issues))
        ->toBe(['firewall.lifecycle_not_active', 'firewall.lifecycle_not_active'])
        ->and($report->issues[0]->resourceId)
        ->toBe($late->id)
        ->and($report->issues[1]->resourceId)
        ->toBe($early->id);
});

it('maps backend, rule, exact, typed failure, and excludes other nodes', function (): void {
    $node = Node::create([
        'name' => 'target',
        'platform' => 'linux',
        'wireguard_ip' => '10.0.0.2',
        'public_ssh_host' => '192.0.2.2',
        'user' => 'orbit',
    ]);
    $other = Node::create([
        'name' => 'other',
        'platform' => 'linux',
        'wireguard_ip' => '10.0.0.3',
        'public_ssh_host' => '192.0.2.3',
        'user' => 'orbit',
    ]);
    foreach ([
        ['inactive', FirewallBackendStatus::Inactive],
        ['absent',   FirewallBackendStatus::Absent],
        ['missing',  FirewallRuleInspectionStatus::Missing],
        ['mismatch', FirewallRuleInspectionStatus::Drift],
        ['exact',    FirewallRuleInspectionStatus::Exact],
        ['failed',   null],
    ] as [$name, $state]) {
        FirewallRule::create([
            'node_id' => $node->id,
            'name' => $name,
            'action' => 'allow',
            'source' => 'any',
            'protocol' => 'tcp',
            'port' => '443',
            'status' => LifecycleStatus::Active,
        ]);
    }
    FirewallRule::create([
        'node_id' => $other->id,
        'name' => 'other',
        'action' => 'allow',
        'source' => 'any',
        'protocol' => 'tcp',
        'port' => '443',
        'status' => LifecycleStatus::Active,
    ]);
    $calls = [];
    $report = new FirewallDoctorProbe(new class($calls) implements FirewallInspector {
        public function __construct(
            private array &$calls,
        ) {}

        public function inspect(FirewallInspectionTarget $target): FirewallInspectionData
        {
            $this->calls[] = $target->resourceName;

            return match ($target->resourceName) {
                'inactive' => new FirewallInspectionData(
                    FirewallBackendStatus::Inactive,
                    FirewallRuleInspectionStatus::Missing,
                ),
                'absent' => new FirewallInspectionData(
                    FirewallBackendStatus::Absent,
                    FirewallRuleInspectionStatus::Missing,
                ),
                'missing' => new FirewallInspectionData(
                    FirewallBackendStatus::Active,
                    FirewallRuleInspectionStatus::Missing,
                ),
                'mismatch' => new FirewallInspectionData(
                    FirewallBackendStatus::Active,
                    FirewallRuleInspectionStatus::Drift,
                ),
                'exact' => new FirewallInspectionData(
                    FirewallBackendStatus::Active,
                    FirewallRuleInspectionStatus::Exact,
                ),
                default => throw new DoctorInspectionException,
            };
        }
    }, new FirewallExpectationProviderFake)->inspect(
        new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', null, true)),
    );
    expect($report->checked)
        ->toBe(6)
        ->and($report->issues)
        ->toHaveCount(5)
        ->and($calls)
        ->toBe(['inactive', 'absent', 'missing', 'mismatch', 'exact', 'failed']);
});

it('orders persisted issues before bounded Metrics issues without increasing checked', function (): void {
    $node = Node::create([
        'name' => 'metrics-target',
        'platform' => 'linux',
        'wireguard_ip' => '10.44.0.3',
        'public_ssh_host' => '192.0.2.3',
        'user' => 'orbit',
    ]);
    $persisted = FirewallRule::create([
        'node_id' => $node->id,
        'name' => 'persisted',
        'action' => 'allow',
        'source' => 'any',
        'protocol' => 'tcp',
        'port' => '443',
        'status' => LifecycleStatus::Active,
    ]);
    $exporter = firewallMetricsTarget($node, 'orbit:metrics-node-exporter', 'Metrics node exporter');
    $publication = firewallMetricsTarget($node, 'orbit:metrics-grafana-upstream', 'Metrics Grafana upstream');
    $inspector = new class implements FirewallInspector {
        public function inspect(FirewallInspectionTarget $target): FirewallInspectionData
        {
            return match ($target->resourceId) {
                'orbit:metrics-grafana-upstream' => throw new DoctorInspectionException,
                'orbit:metrics-node-exporter' => new FirewallInspectionData(
                    FirewallBackendStatus::Active,
                    FirewallRuleInspectionStatus::Drift,
                ),
                default => new FirewallInspectionData(
                    FirewallBackendStatus::Active,
                    FirewallRuleInspectionStatus::Missing,
                ),
            };
        }
    };

    $report = new FirewallDoctorProbe(
        $inspector,
        new FirewallExpectationProviderFake([$exporter, $publication]),
    )->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', null, true)));

    expect($report->checked)
        ->toBe(1)
        ->and(array_map(static fn (DoctorIssueData $issue): int|string|null => $issue->resourceId, $report->issues))
        ->toBe([$persisted->id, 'orbit:metrics-node-exporter', 'orbit:metrics-grafana-upstream'])
        ->and(array_map(static fn (DoctorIssueData $issue): string => $issue->code, $report->issues))
        ->toBe(['firewall.rule_missing', 'firewall.rule_mismatch', 'firewall.inspection_failed'])
        ->and(json_encode($report->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain('credential-sentinel', 'sudo', 'ufw', 'stdout', 'stderr');
});

it('checks a Metrics expectation with zero persisted rows and short-circuits when unreachable', function (): void {
    $node = Node::create([
        'name' => 'metrics-only',
        'platform' => 'linux',
        'wireguard_ip' => '10.44.0.4',
        'public_ssh_host' => '192.0.2.4',
        'user' => 'orbit',
    ]);
    $target = firewallMetricsTarget($node, 'orbit:metrics-node-exporter', 'Metrics node exporter');
    $calls = 0;
    $inspector = new class($calls) implements FirewallInspector {
        public function __construct(
            private int &$calls,
        ) {}

        public function inspect(FirewallInspectionTarget $target): FirewallInspectionData
        {
            $this->calls++;

            return new FirewallInspectionData(FirewallBackendStatus::Active, FirewallRuleInspectionStatus::Exact);
        }
    };
    $probe = new FirewallDoctorProbe($inspector, new FirewallExpectationProviderFake([$target]));

    $healthy = $probe->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', null, true)));
    $unreachable = $probe->inspect(new DoctorNodeContext($node, new NodeInspectionData(false, null, null, null)));

    expect($healthy->checked)
        ->toBe(0)
        ->and($healthy->issues)
        ->toBe([])
        ->and($unreachable->checked)
        ->toBe(0)
        ->and($unreachable->issues)
        ->toHaveCount(1)
        ->and($unreachable->issues[0]->code)
        ->toBe('firewall.node_unreachable')
        ->and($calls)
        ->toBe(1);
});

function firewallMetricsTarget(Node $node, string $resourceId, string $resourceName): FirewallInspectionTarget
{
    return new FirewallInspectionTarget(
        node: $node,
        shape: new FirewallInspectionShape(
            comment: $resourceId,
            action: 'allow',
            direction: 'in',
            source: '10.44.0.3',
            destination: (string) $node->wireguard_ip,
            port: '9100',
            protocol: 'tcp',
            inInterface: 'orbit',
            outInterface: null,
            family: 'v4',
        ),
        resourceId: $resourceId,
        resourceName: $resourceName,
    );
}

/** @mago-expect lint:single-class-per-file Test-local fake keeps expectation input explicit. */
final readonly class FirewallExpectationProviderFake implements MetricsFirewallExpectationProvider
{
    /** @param list<FirewallInspectionTarget> $targets */
    public function __construct(
        private array $targets = [],
    ) {}

    public function for(Node $node): array
    {
        return $this->targets;
    }
}
